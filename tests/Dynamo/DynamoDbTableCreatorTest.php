<?php

namespace Rikudou\Tests\DynamoDbCache\Dynamo;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ResourceInUseException;
use AsyncAws\DynamoDb\Exception\ResourceNotFoundException;
use AsyncAws\DynamoDb\Input\DeleteTableInput;
use Rikudou\DynamoDbCache\Dynamo\DynamoDbTableCreator;
use PHPUnit\Framework\TestCase;
use Rikudou\DynamoDbCache\Dynamo\DynamoDbTableCreatorInterface;
use Rikudou\DynamoDbCache\DynamoDbCacheBuilder;
use RuntimeException;

class DynamoDbTableCreatorTest extends TestCase
{
    /**
     * @var DynamoDbTableCreator
     */
    private $instance;

    /**
     * @var DynamoDbClient
     */
    private $dynamo;

    protected function setUp(): void
    {
        var_dump(count($_ENV), $_ENV['RIKUDOU_TEST_DYNAMO_TABLE'] ?? null);exit;
        if (
            !getenv('AWS_ACCESS_KEY_ID')
            || !getenv('AWS_SECRET_ACCESS_KEY')
            || !getenv('RIKUDOU_TEST_DYNAMO_TABLE')
        ) {
            $this->markTestSkipped('This test needs access to real AWS servers');
        }

        $this->dynamo = new DynamoDbClient();
        $cache = DynamoDbCacheBuilder::create(
            getenv('RIKUDOU_TEST_DYNAMO_TABLE'),
            $this->dynamo,
        )->build();
        $this->instance = new DynamoDbTableCreator($cache);
    }

    protected function tearDown(): void
    {
        $table = getenv('RIKUDOU_TEST_DYNAMO_TABLE');
        try {
            $this->dynamo->deleteTable(new DeleteTableInput([
                'TableName' => $table,
            ]))->resolve();
        } catch (ResourceNotFoundException $ignore) {
        }
        $count = 0;
        while ($this->instance->exists()) {
            usleep(2000);
            if ($count === 100) {
                throw new RuntimeException("Table wasn't cleaned up in {$count} iterations");
            }
            ++$count;
        }
    }

    public function testExists()
    {
        self::assertFalse($this->instance->exists());
        self::assertTrue($this->instance->create());
        self::assertTrue($this->instance->exists());
    }

    public function testCreate()
    {
        self::assertFalse($this->instance->exists());

        self::assertTrue($this->instance->create());
        self::assertTrue($this->instance->exists());
        self::assertFalse($this->instance->create(DynamoDbTableCreatorInterface::MODE_PAY_PER_REQUEST, false));

        $this->expectException(ResourceInUseException::class);
        $this->instance->create();
    }

    public function testCreateIfNotExists()
    {
        self::assertFalse($this->instance->exists());

        self::assertTrue($this->instance->createIfNotExists());
        self::assertTrue($this->instance->exists());
        self::assertTrue($this->instance->createIfNotExists());
    }
}
