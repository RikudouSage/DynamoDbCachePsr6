<?php

namespace Rikudou\DynamoDbCache\Dynamo;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\TableStatus;
use AsyncAws\DynamoDb\Exception\ResourceNotFoundException;
use ReflectionObject;
use Rikudou\DynamoDbCache\DynamoDbCache;

final class DynamoDbTableCreator implements DynamoDbTableCreatorInterface
{
    /**
     * @var DynamoDbCache
     */
    private $cache;

    /**
     * @var string
     */
    private $tableName;

    /**
     * @var DynamoDbClient
     */
    private $awsClient;

    /**
     * @var string
     */
    private $primaryField;

    /**
     * @var string
     */
    private $ttlField;

    public function __construct(DynamoDbCache $cache)
    {
        $this->cache = $cache;
        $this->initialize();
    }

    public function exists(): bool
    {
        try {
            $this->awsClient->describeTable([
                'TableName' => $this->tableName,
            ]);

            return true;
        } catch (ResourceNotFoundException $e) {
            return false;
        }
    }

    public function create(string $mode = self::MODE_PAY_PER_REQUEST, bool $throw = true): bool
    {
        try {
            $this->awsClient->createTable([
                'AttributeDefinitions' => [
                    [
                        'AttributeName' => $this->primaryField,
                        'AttributeType' => 'S',
                    ],
                ],
                'BillingMode' => $mode,
                'KeySchema' => [
                    [
                        'AttributeName' => $this->primaryField,
                        'KeyType' => 'HASH',
                    ],
                ],
                'TableName' => $this->tableName,
            ]);
            while (!$this->isActive()) {
                usleep(2000);
            }
            $this->awsClient->updateTimeToLive([
                'TableName' => $this->tableName,
                'TimeToLiveSpecification' => [
                    'AttributeName' => $this->ttlField,
                    'Enabled' => true,
                ],
            ]);

            return true;
        } catch (ClientException $e) {
            if ($throw) {
                throw $e;
            }

            return false;
        }
    }

    public function createIfNotExists(string $mode = self::MODE_PAY_PER_REQUEST, bool $throw = true): bool
    {
        if (!$this->exists()) {
            return $this->create($mode, $throw);
        }

        return true;
    }

    private function initialize(): void
    {
        $reflection = new ReflectionObject($this->cache);

        $reflectionTableName = $reflection->getProperty('tableName');
        $reflectionClient = $reflection->getProperty('client');
        $reflectionPrimaryField = $reflection->getProperty('primaryField');
        $reflectionTtlField = $reflection->getProperty('ttlField');
        $reflectionValueField = $reflection->getProperty('valueField');

        $reflectionTableName->setAccessible(true);
        $reflectionClient->setAccessible(true);
        $reflectionPrimaryField->setAccessible(true);
        $reflectionTtlField->setAccessible(true);
        $reflectionValueField->setAccessible(true);

        $this->tableName = $reflectionTableName->getValue($this->cache);
        $this->awsClient = $reflectionClient->getValue($this->cache);
        $this->primaryField = $reflectionPrimaryField->getValue($this->cache);
        $this->ttlField = $reflectionTtlField->getValue($this->cache);
    }

    private function isActive(): bool
    {
        if (!$this->exists()) {
            return false;
        }
        $result = $this->awsClient->describeTable([
            'TableName' => $this->tableName,
        ])->getTable();
        if ($result === null) {
            return false;
        }

        return $result->getTableStatus() === TableStatus::ACTIVE;
    }
}
