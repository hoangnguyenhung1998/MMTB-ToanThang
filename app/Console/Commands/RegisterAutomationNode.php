<?php

namespace App\Console\Commands;

use App\Models\AutomationNode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RegisterAutomationNode extends Command
{
    protected $signature = 'automation:register-node {key} {--name=} {--location=}';

    protected $description = 'Đăng ký node giám sát và cấp lại token heartbeat';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $token = Str::random(64);
        AutomationNode::query()->updateOrCreate(
            ['node_key' => $key],
            [
                'name' => $this->option('name') ?: $key,
                'location' => $this->option('location'),
                'token_hash' => hash('sha256', $token),
                'enabled' => true,
            ]
        );

        $this->info('Đã đăng ký node '.$key.'. Token chỉ hiển thị lần này:');
        $this->line($token);

        return self::SUCCESS;
    }
}
