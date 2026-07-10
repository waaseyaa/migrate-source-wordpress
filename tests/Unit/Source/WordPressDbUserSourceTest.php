<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Source;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressDbUserSource;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
function makeDbFixture(string $prefix = 'wp_'): DatabaseInterface
{
    $db = DBALDatabase::createSqlite();

    $db->query(\sprintf(
        'CREATE TABLE %1$susers (
            ID INTEGER PRIMARY KEY,
            user_login TEXT NOT NULL,
            user_pass TEXT NOT NULL DEFAULT \'\',
            user_email TEXT NOT NULL,
            user_registered TEXT NOT NULL,
            user_status INTEGER NOT NULL DEFAULT 0,
            display_name TEXT NOT NULL DEFAULT \'\'
        )',
        $prefix,
    ));

    $db->query(\sprintf(
        'CREATE TABLE %1$susermeta (
            umeta_id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            meta_key TEXT,
            meta_value TEXT
        )',
        $prefix,
    ));

    return $db;
}

function seedUser(
    DatabaseInterface $db,
    int $id,
    string $login,
    string $email,
    string $registered = '2020-01-15 10:30:00',
    string $displayName = '',
    int $status = 0,
    string $prefix = 'wp_',
): void {
    $db->insert($prefix . 'users')->values([
        'ID' => $id,
        'user_login' => $login,
        'user_pass' => '',
        'user_email' => $email,
        'user_registered' => $registered,
        'user_status' => $status,
        'display_name' => $displayName,
    ])->execute();
}

function seedMeta(DatabaseInterface $db, int $userId, string $metaKey, ?string $value, string $prefix = 'wp_'): void
{
    $db->insert($prefix . 'usermeta')->values([
        'user_id' => $userId,
        'meta_key' => $metaKey,
        'meta_value' => $value,
    ])->execute();
}

it('declares plugin metadata', function () {
    $db = makeDbFixture();
    $source = new WordPressDbUserSource($db);
    expect($source->id())->toBe('wordpress_db_user');
    expect($source->stability())->toBe('stable');
});

it('emits the same base field shape as WordPressUserSource for an author-like user', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test', '2020-01-15 10:30:00', 'Test User One');
    seedMeta($db, 1, 'first_name', 'Test');
    seedMeta($db, 1, 'last_name', 'One');
    seedMeta($db, 1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records)->toHaveCount(1);

    $fields = $records[0]->fields;
    expect($fields['id'])->toBe(1);
    expect($fields['login'])->toBe('testuser1');
    expect($fields['email'])->toBe('user1@example.test');
    expect($fields['display_name'])->toBe('Test User One');
    expect($fields['first_name'])->toBe('Test');
    expect($fields['last_name'])->toBe('One');
    expect($fields['registered'])->toBe('2020-01-15T10:30:00+00:00');
    expect($fields['role'])->toBe('administrator');

    // Base shape parity: every key WordPressUserSource emits is present here too.
    $wxrShapeKeys = ['id', 'login', 'email', 'display_name', 'first_name', 'last_name', 'registered', 'role', '_extra'];
    foreach ($wxrShapeKeys as $key) {
        expect($fields)->toHaveKey($key);
    }
});

it('falls back to login for display_name when display_name is empty', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test', '2020-01-15 10:30:00', '');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records[0]->fields['display_name'])->toBe('testuser1');
});

it('derives role from the first truthy capabilities key', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');
    seedMeta($db, 1, 'wp_capabilities', 'a:1:{s:10:"subscriber";b:1;}');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records[0]->fields['role'])->toBe('subscriber');
});

it('returns null role when capabilities meta is missing', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records[0]->fields['role'])->toBeNull();
});

it('returns null role for malformed serialized capabilities without crashing', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');
    seedMeta($db, 1, 'wp_capabilities', 'not-serialized-garbage{{{');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records[0]->fields['role'])->toBeNull();
});

it('returns null role when capabilities unserializes to an empty array', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');
    seedMeta($db, 1, 'wp_capabilities', 'a:0:{}');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records[0]->fields['role'])->toBeNull();
});

it('passes metaFields through raw, keyed by the record field name', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');
    seedMeta($db, 1, 'mepr-custom-consent', 'yes');
    seedMeta($db, 1, 'mepr-account-disabled', '1');

    $source = new WordPressDbUserSource($db, metaFields: [
        'consent' => 'mepr-custom-consent',
        'disabled' => 'mepr-account-disabled',
    ]);

    $records = iterator_to_array($source->records(), false);
    expect($records[0]->fields['consent'])->toBe('yes');
    expect($records[0]->fields['disabled'])->toBe('1');
});

it('emits null for a metaFields entry the user has no row for', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');

    $source = new WordPressDbUserSource($db, metaFields: ['consent' => 'mepr-custom-consent']);
    $records = iterator_to_array($source->records(), false);
    expect($records[0]->fields['consent'])->toBeNull();
});

it('does not let a metaFields entry named role override the derived role', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');
    seedMeta($db, 1, 'wp_capabilities', 'a:1:{s:10:"subscriber";b:1;}');
    seedMeta($db, 1, 'some_other_role_meta', 'bogus');

    $source = new WordPressDbUserSource($db, metaFields: ['role' => 'some_other_role_meta']);
    $records = iterator_to_array($source->records(), false);
    expect($records[0]->fields['role'])->toBe('subscriber');
});

it('emits the raw user_status column as an int status field', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test', status: 2);

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records[0]->fields['status'])->toBe(2);
});

it('emits a user with no usermeta rows at all without crashing', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'testuser1', 'user1@example.test');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect($records)->toHaveCount(1);
    expect($records[0]->fields['first_name'])->toBeNull();
    expect($records[0]->fields['last_name'])->toBeNull();
    expect($records[0]->fields['role'])->toBeNull();
});

it('respects a custom table prefix for both users and usermeta', function () {
    $db = makeDbFixture('custom_');
    seedUser($db, 1, 'testuser1', 'user1@example.test', prefix: 'custom_');
    seedMeta($db, 1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}', prefix: 'custom_');

    $records = iterator_to_array((new WordPressDbUserSource($db, tablePrefix: 'custom_'))->records(), false);
    expect($records)->toHaveCount(1);
    expect($records[0]->fields['login'])->toBe('testuser1');
    // Under the custom prefix, capabilities meta key is 'custom_capabilities' —
    // the default wp_ lookup must NOT find it.
});

it('derives role using the custom prefix capabilities key, not the default wp_ key', function () {
    $db = makeDbFixture('custom_');
    seedUser($db, 1, 'testuser1', 'user1@example.test', prefix: 'custom_');
    seedMeta($db, 1, 'custom_capabilities', 'a:1:{s:6:"editor";b:1;}', prefix: 'custom_');
    // A stray wp_capabilities row (as if copy-pasted) must be ignored under a custom prefix.
    seedMeta($db, 1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}', prefix: 'custom_');

    $records = iterator_to_array((new WordPressDbUserSource($db, tablePrefix: 'custom_'))->records(), false);
    expect($records[0]->fields['role'])->toBe('editor');
});

it('orders records deterministically by ID', function () {
    $db = makeDbFixture();
    seedUser($db, 3, 'third', 'third@example.test');
    seedUser($db, 1, 'first', 'first@example.test');
    seedUser($db, 2, 'second', 'second@example.test');

    $records = iterator_to_array((new WordPressDbUserSource($db))->records(), false);
    expect(array_map(static fn (SourceRecord $r) => $r->fields['login'], $records))
        ->toBe(['first', 'second', 'third']);
});

it('raises SourceReadException when the users table is missing', function () {
    $db = DBALDatabase::createSqlite();
    $source = new WordPressDbUserSource($db, migrationId: 'test_migration');

    expect(fn () => iterator_to_array($source->records(), false))
        ->toThrow(SourceReadException::class);
});

it('computes a SourceId identical in shape to WordPressUserSource (regression: int-vs-string keying)', function () {
    $db = makeDbFixture();
    seedUser($db, 42, 'testuser42', 'user42@example.test');

    $dbSource = new WordPressDbUserSource($db);
    $dbRecords = iterator_to_array($dbSource->records(), false);
    $dbSourceId = $dbSource->sourceIdFor($dbRecords[0]);

    // WordPressUserSource computes sourceIdFor purely from $record->field('id')
    // — construct a matching SourceRecord by hand to compare hashes without
    // needing a WXR fixture with id 42.
    $wxrLikeRecord = new SourceRecord(WordPressUserSource::SOURCE_TYPE, ['id' => 42]);
    $expected = new SourceId(WordPressUserSource::SOURCE_TYPE, ['id' => '42']);

    expect($dbSourceId->sourceType)->toBe(WordPressUserSource::SOURCE_TYPE);
    expect($dbSourceId->keys)->toBe(['id' => '42']);
    expect($dbSourceId->hash())->toBe($expected->hash());

    // And WordPressUserSource::sourceIdFor() on an equivalent record shape
    // produces the exact same hash (id-map continuity is keyed on this).
    $reader = new \Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader(
        __DIR__ . '/../../../testing/Fixtures/small-site.xml',
    );
    $wxrSourceId = (new WordPressUserSource($reader))->sourceIdFor($wxrLikeRecord);
    expect($wxrSourceId->hash())->toBe($dbSourceId->hash());
});

it('count() returns the row count', function () {
    $db = makeDbFixture();
    seedUser($db, 1, 'a', 'a@example.test');
    seedUser($db, 2, 'b', 'b@example.test');

    expect((new WordPressDbUserSource($db))->count())->toBe(2);
});

it('count() returns null when the users table is missing', function () {
    $db = DBALDatabase::createSqlite();
    expect((new WordPressDbUserSource($db))->count())->toBeNull();
});
