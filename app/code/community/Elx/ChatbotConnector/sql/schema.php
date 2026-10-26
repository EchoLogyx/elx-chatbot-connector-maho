<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return function (Schema $schema): void {
    $settings = $schema->createTable('elxchatbot_settings');
    $settings->addColumn('setting_id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
    $settings->addColumn('scope', Types::STRING, ['length' => 32]);
    $settings->addColumn('scope_id', Types::INTEGER, ['unsigned' => true, 'default' => 0]);
    $settings->addColumn('path', Types::STRING, ['length' => 255]);
    $settings->addColumn('value', Types::TEXT, ['notnull' => false]);
    $settings->addPrimaryKeyConstraint(
        PrimaryKeyConstraint::editor()->setUnquotedColumnNames('setting_id')->create(),
    );
    $settings->addUniqueIndex(['scope', 'scope_id', 'path']);
    $settings->addIndex(['path']);
    $settings->setComment('ELX Chatbot Connector settings');
};
