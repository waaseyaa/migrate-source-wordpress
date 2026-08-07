<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

/** Builds reference-resolution repositories across the supported storage boundary versions. */
final class WpRefRepositoryFactory
{
    private const string AUTHORIZATION_PRINCIPAL = 'Waaseyaa\\Access\\AuthorizationPrincipal';
    private const string STORAGE_BOUNDARY = 'Waaseyaa\\EntityStorage\\Driver\\StorageBoundary';
    private const string SQL_STORAGE_DRIVER_V2 = 'Waaseyaa\\EntityStorage\\Driver\\SqlStorageDriverV2';

    public static function create(
        EntityTypeInterface $entityType,
        SingleConnectionResolver $resolver,
        EventDispatcherInterface $dispatcher,
    ): EntityRepository {
        $legacyDriver = new SqlStorageDriver($resolver, 'id');

        $repositoryClass = new \ReflectionClass(EntityRepository::class);

        $boundaryClassName = self::STORAGE_BOUNDARY;
        if (!class_exists($boundaryClassName)) {
            $repository = $repositoryClass->newInstanceArgs([
                'entityType' => $entityType,
                'driver' => $legacyDriver,
                'eventDispatcher' => $dispatcher,
            ]);

            return self::requireRepository($repository);
        }

        $boundary = new $boundaryClassName();
        $boundaryClass = new \ReflectionObject($boundary);
        $driverClassName = self::SQL_STORAGE_DRIVER_V2;
        if (!class_exists($driverClassName)) {
            throw new \LogicException('The V2 storage boundary exists without its SQL driver.');
        }
        $driver = new $driverClassName(
            $legacyDriver,
            $boundaryClass->getMethod('driverRowFactory')->invoke($boundary),
            $boundaryClass->getMethod('driverSnapshotReader')->invoke($boundary),
        );

        $repository = $repositoryClass->newInstanceArgs([
            'entityType' => $entityType,
            'driver' => $driver,
            'eventDispatcher' => $dispatcher,
            'storageBoundary' => $boundary,
        ]);

        return self::requireRepository($repository);
    }

    public static function systemAccount(): AccountInterface
    {
        $principalClassName = self::AUTHORIZATION_PRINCIPAL;
        if (!class_exists($principalClassName)) {
            return new RefTestSystemAccount();
        }

        $principal = new $principalClassName(
            'system-migration',
            true,
            ['administrator'],
            [],
            'wordpress-reference-fixture',
        );
        return self::requireAccount($principal);
    }

    /** Inspect the disposable fixture database without bypassing application field-read policy. */
    public static function storedValue(DBALDatabase $database, EntityInterface $entity, string $field): mixed
    {
        $entityType = $entity->getEntityTypeId();
        if (!preg_match('/^[a-z_]+$/', $entityType) || !preg_match('/^[a-z_]+$/', $field)) {
            throw new \InvalidArgumentException('Fixture storage identifiers must use lowercase snake case.');
        }

        $payload = $database->getConnection()->fetchOne(
            sprintf('SELECT "_data" FROM "%s" WHERE "id" = ?', $entityType),
            [$entity->id()],
        );
        if ($payload === false) {
            throw new \LogicException(sprintf('Stored %s entity was not found.', $entityType));
        }
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $values = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($values)) {
            throw new \LogicException(sprintf('Stored %s payload must decode to an array.', $entityType));
        }

        return $values[$field] ?? null;
    }

    private static function requireRepository(object $repository): EntityRepository
    {
        if (!$repository instanceof EntityRepository) {
            throw new \LogicException('EntityRepository reflection returned an unexpected object.');
        }

        return $repository;
    }

    private static function requireAccount(object $account): AccountInterface
    {
        if (!$account instanceof AccountInterface) {
            throw new \LogicException('AuthorizationPrincipal must implement AccountInterface.');
        }

        return $account;
    }
}
