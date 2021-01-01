<?php

namespace PHPSTORM_META {

    use Rikudou\DynamoDbCache\Dynamo\DynamoDbTableCreator;

    registerArgumentsSet(
        'dynamoDbPayModes',
        DynamoDbTableCreator::MODE_PROVISIONED,
        DynamoDbTableCreator::MODE_PAY_PER_REQUEST
    );

    expectedArguments(
        DynamoDbTableCreator::create(),
        0,
        argumentsSet('dynamoDbPayModes')
    );
    expectedArguments(
        DynamoDbTableCreator::createIfNotExists(),
        0,
        argumentsSet('dynamoDbPayModes')
    );
}