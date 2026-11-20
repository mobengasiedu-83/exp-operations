<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigExpPbxSet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:exp-pbx:set {name} {host} {port} {username} {password?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update an EXP PBX connection configuration entry.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name');
        $host = $this->argument('host');
        $port = (int) $this->argument('port');
        $username = $this->argument('username');
        $password = $this->argument('password');
        if ($password === null && $this->input->isInteractive()) {
            $password = $this->secret('Read-only AMI password');
        }
        foreach ([$name, $host, $username, $password] as $value) {
            if (! is_string($value) || trim($value) === '' || strpbrk($value, "\r\n") !== false) {
                $this->error('Connection fields must be non-empty and contain no line breaks.');

                return self::FAILURE;
            }
        }

        if ($port < 1 || $port > 65535) {
            $this->error('AMI port must be between 1 and 65535.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $host, $port, $username, $password): PbxConnection {
            return PbxConnection::query()->updateOrCreate(
                ['name' => $name],
                [
                    'host' => $host,
                    'ami_port' => $port,
                    'ami_username' => $username,
                    'ami_password' => $password,
                ]
            );
        });

        $this->info(\sprintf('PBX connection "%s" saved successfully.', $name));

        return self::SUCCESS;
    }
}
