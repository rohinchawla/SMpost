<?php
define('GO_BOOT', true);
require __DIR__ . '/../webapp/app/bootstrap.php';
Db::connect(go_setting('db'));
Db::exec('DELETE FROM api_keys');
foreach (array_keys(ApiKeys::SCOPES) as $agent) {
    $raw = 'go_test_' . strtolower($agent) . '_key';
    ApiKeys::store($agent, $raw, "local test key {$agent}");
    echo str_pad($agent, 4), $raw, '  prefix=', substr($raw, 0, 12), PHP_EOL;
}
if (Db::row('SELECT id FROM users LIMIT 1') === null) {
    UiAuth::createUser('rc@gojobs.biz', 'local-test-password-1234', 'owner', 'Rohin');
    echo "owner account created: rc@gojobs.biz\n";
}
echo "keys in table: ", Db::one('SELECT COUNT(*) FROM api_keys'), PHP_EOL;
