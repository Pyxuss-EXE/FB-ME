<?php
namespace FBBot;

use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Update;

class TelegramBot
{
    private $telegram;
    private $logger;
    private $jobManager;

    public function __construct()
    {
        // Get token from environment
        $token = getenv('BOT_TOKEN');
        if (!$token) {
            $token = $_ENV['BOT_TOKEN'] ?? '';
        }
        
        // Initialize Telegram API
        $this->telegram = new Api($token);
        $this->logger = Logger::getInstance();
        $this->jobManager = new JobManager();
        
        $this->logger->info("TelegramBot initialized with token: " . substr($token, 0, 10) . "...");
    }

    public function handleUpdate(Update $update)
    {
        $message = $update->getMessage();
        if (!$message) return;
        
        $chatId = $message->getChat()->getId();
        $text = $message->getText();
        $userId = $message->getFrom()->getId();

        $this->logger->info("Received message from user $userId: $text");

        if ($text) {
            $this->handleCommand($chatId, $userId, $text);
        } elseif ($message->has('document')) {
            $this->handleDocument($chatId, $userId, $message->getDocument());
        }
    }

    private function handleCommand($chatId, $userId, $text)
    {
        $text = trim($text);
        
        if ($text === '/start') {
            $this->sendMessage($chatId,
                "👋 Welcome to Facebook Number Checker Bot!\n\n"
                . "Commands:\n"
                . "/upload - Upload numbers.txt file\n"
                . "/status - Check current job status\n"
                . "/results - Download results\n"
                . "/cancel - Cancel current job\n"
                . "/help - Show this help"
            );
        } 
        elseif ($text === '/upload') {
            $this->sendMessage($chatId, "Please send me a `numbers.txt` file containing one phone number per line.", ['parse_mode' => 'Markdown']);
        } 
        elseif ($text === '/status') {
            $this->sendStatus($chatId, $userId);
        } 
        elseif ($text === '/results') {
            $this->sendResults($chatId, $userId);
        } 
        elseif ($text === '/cancel') {
            $this->cancelJob($chatId, $userId);
        } 
        elseif ($text === '/help') {
            $this->sendMessage($chatId,
                "📚 *Available Commands:*\n\n"
                . "/start - Welcome message\n"
                . "/upload - Upload numbers.txt file\n"
                . "/status - Check current job status\n"
                . "/results - Download results\n"
                . "/cancel - Cancel current job\n"
                . "/help - Show this help"
            , ['parse_mode' => 'Markdown']);
        }
        else {
            $this->sendMessage($chatId, "Unknown command. Type /help for available commands.");
        }
    }

    private function handleDocument($chatId, $userId, $document)
    {
        try {
            $this->sendMessage($chatId, "📥 Downloading your file...");
            
            $file = $this->telegram->getFile(['file_id' => $document->getFileId()]);
            
            // Create uploads directory if not exists
            $uploadDir = storage_path('uploads/');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $filePath = $this->telegram->downloadFile($file, $uploadDir);

            // Read and validate numbers
            $content = file_get_contents($filePath);
            $lines = explode("\n", $content);
            $numbers = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Remove any non-numeric characters except +
                $clean = preg_replace('/[^0-9+]/', '', $line);
                if (preg_match('/^\+?[0-9]{10,15}$/', $clean)) {
                    $numbers[] = $clean;
                }
            }
            
            $numbers = array_values(array_unique($numbers));

            if (empty($numbers)) {
                $this->sendMessage($chatId, "❌ No valid phone numbers found in the file.");
                unlink($filePath);
                return;
            }

            // Create job
            $jobId = $this->jobManager->createJob($userId, $numbers);
            
            // Store user's current job
            $userStorage = new Storage('users.json');
            $userStorage->acquireLock();
            $users = $userStorage->get('users', []);
            $users[$userId] = ['current_job' => $jobId];
            $userStorage->set('users', $users);
            $userStorage->save();
            $userStorage->releaseLock();

            $this->sendMessage($chatId,
                "✅ File accepted! Found " . count($numbers) . " valid numbers.\n"
                . "Job ID: `$jobId`\n"
                . "Processing will begin shortly. Use /status to check progress.",
                ['parse_mode' => 'Markdown']
            );

            // Cleanup uploaded file
            unlink($filePath);
            
        } catch (\Exception $e) {
            $this->logger->error("File upload error: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ Error processing file: " . $e->getMessage());
        }
    }

    private function sendStatus($chatId, $userId)
    {
        $userStorage = new Storage('users.json');
        $users = $userStorage->get('users', []);
        $jobId = $users[$userId]['current_job'] ?? null;

        if (!$jobId) {
            $this->sendMessage($chatId, "No active job found. Use /upload to start one.");
            return;
        }

        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            $this->sendMessage($chatId, "Job not found.");
            return;
        }

        $percent = $job['total'] > 0 ? round(($job['processed'] / $job['total']) * 100, 2) : 0;
        
        // Create progress bar
        $barLength = 20;
        $filled = round(($job['processed'] / $job['total']) * $barLength);
        $progressBar = '[' . str_repeat('█', $filled) . str_repeat('░', $barLength - $filled) . ']';

        $message = "📊 *Job Status*\n\n"
            . "Job ID: `{$job['id']}`\n"
            . "Status: *{$job['status']}*\n"
            . "Progress: {$progressBar} {$percent}%\n"
            . "Processed: {$job['processed']}/{$job['total']}\n\n"
            . "✅ Valid (OTP sent): {$job['valid']}\n"
            . "❌ Invalid (not found): {$job['invalid']}\n"
            . "👥 Multi-account: {$job['multi_account']}\n"
            . "⚠️ Errors: {$job['errors']}\n\n"
            . "Last update: " . date('Y-m-d H:i:s', $job['updated_at']);

        $this->sendMessage($chatId, $message, ['parse_mode' => 'Markdown']);
    }

    private function sendResults($chatId, $userId)
    {
        $userStorage = new Storage('users.json');
        $users = $userStorage->get('users', []);
        $jobId = $users[$userId]['current_job'] ?? null;

        if (!$jobId) {
            $this->sendMessage($chatId, "No job found.");
            return;
        }

        $resultFile = storage_path("results/job_{$jobId}.json");
        if (!file_exists($resultFile)) {
            $this->sendMessage($chatId, "Results not ready yet. Check /status.");
            return;
        }

        try {
            $this->telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => InputFile::create($resultFile, "results_{$jobId}.json"),
                'caption' => "📁 Results for job $jobId"
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to send document: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ Failed to send results file.");
        }
    }

    private function cancelJob($chatId, $userId)
    {
        $userStorage = new Storage('users.json');
        $users = $userStorage->get('users', []);
        $jobId = $users[$userId]['current_job'] ?? null;

        if ($jobId) {
            $this->jobManager->updateJob($jobId, ['status' => 'cancelled']);
            unset($users[$userId]);
            $userStorage->acquireLock();
            $userStorage->set('users', $users);
            $userStorage->save();
            $userStorage->releaseLock();
            $this->sendMessage($chatId, "✅ Job cancelled.");
        } else {
            $this->sendMessage($chatId, "No active job to cancel.");
        }
    }

    private function sendMessage($chatId, $text, $options = [])
    {
        try {
            $this->telegram->sendMessage(array_merge([
                'chat_id' => $chatId,
                'text' => $text
            ], $options));
        } catch (\Exception $e) {
            $this->logger->error("Failed to send message: " . $e->getMessage());
        }
    }

    public function run()
    {
        $this->logger->info("Bot started with long polling");
        $lastUpdateId = 0;
        
        while (true) {
            try {
                $updates = $this->telegram->getUpdates([
                    'offset' => $lastUpdateId + 1,
                    'timeout' => 30
                ]);

                foreach ($updates as $update) {
                    $lastUpdateId = $update->getUpdateId();
                    $this->handleUpdate($update);
                }
            } catch (\Exception $e) {
                $this->logger->error("Polling error: " . $e->getMessage());
                sleep(5); // Wait before retrying
            }
        }
    }
}
