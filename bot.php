#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use FBBot\TelegramBot;
use FBBot\Logger;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// IMPORTANT: Set environment variable for Telegram SDK
putenv('TELEGRAM_BOT_TOKEN=' . $_ENV['BOT_TOKEN']);

date_default_timezone_set('UTC');

$logger = Logger::getInstance();
$logger->info("Starting Telegram bot");

try {
    $bot = new TelegramBot();
    $bot->run();
} catch (\Exception $e) {
    $logger->error("Bot crashed: " . $e->getMessage());
    sleep(5);
    $bot = new TelegramBot();
    $bot->run();
}
