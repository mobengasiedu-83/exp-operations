<?php

use App\Models\PbxConnection;
use App\Models\Setting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require '/var/www/exp-operations/vendor/autoload.php';
$app = require '/var/www/exp-operations/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $config = config('services.exp_pbx');
    $name = $config['connection_name'];
    $connection = PbxConnection::query()->where('name', $name)->first();
    if ($connection === null) {
        foreach (['host', 'username', 'password'] as $key) {
            if (! is_string($config[$key]) || trim($config[$key]) === '') {
                throw new RuntimeException('The configured AMI connection is incomplete.');
            }
        }
        $result = Artisan::call('config:exp-pbx:set', [
            'name' => $name, 'host' => $config['host'], 'port' => $config['port'],
            'username' => $config['username'], 'password' => $config['password'], '--no-interaction' => true,
        ]);
        if ($result !== 0) {
            throw new RuntimeException('Unable to save the PBX connection.');
        }
        $connection = PbxConnection::query()->where('name', $name)->firstOrFail();
    }
    $connection->update(['listener_enabled' => true]);
    if (! Setting::get('app.feature_flags.ami_listener', true)) {
        Setting::query()->where('key', 'app.feature_flags.ami_listener')->update(['value' => 'true']);
    }
    echo json_encode(['connection' => $connection->name, 'listener_enabled' => $connection->listener_enabled, 'credentials_configured' => filled($connection->configuration()['username']) && filled($connection->configuration()['password'])]), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "PBX setup failed. Check the configured AMI fields and database access; no credentials were printed.\n");
    exit(1);
}
