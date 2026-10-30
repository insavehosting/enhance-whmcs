<?php
// WHMCS stand-in for the module tests: an in-memory SQLite database behind the
// WHMCS Capsule facade, and the WHMCS functions the module calls, recorded so
// the scenarios can check them.

namespace WHMCS {
    class TransientData
    {
        private static ?self $instance = null;
        private array $data = [];

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function store(string $key, string $value, int $ttl = 0): bool
        {
            $this->data[$key] = $value;
            return true;
        }

        public function retrieve(string $key)
        {
            return $this->data[$key] ?? null;
        }
    }
}

namespace {
    define('WHMCS', true);
    define('ROOTDIR', dirname(__DIR__, 2));

    require __DIR__ . '/vendor/autoload.php';

    use Illuminate\Database\Capsule\Manager;

    class_alias(Manager::class, 'WHMCS\\Database\\Capsule');

    $capsule = new Manager();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();

    $schema = Manager::schema();
    $schema->create('tblclients', function ($t) {
        $t->increments('id');
        foreach (['firstname', 'lastname', 'companyname', 'email', 'status', 'language'] as $c) $t->string($c)->default('');
    });
    $schema->create('tblhosting', function ($t) {
        $t->increments('id');
        foreach (['userid', 'packageid', 'server'] as $c) $t->integer($c)->default(0);
        foreach (['domain', 'domainstatus', 'username', 'subscriptionid', 'regdate', 'lastupdate'] as $c) $t->string($c)->default('');
        $t->text('password')->default('');
        foreach (['diskusage', 'disklimit', 'bwusage', 'bwlimit'] as $c) $t->integer($c)->default(0);
    });
    $schema->create('tblproducts', function ($t) {
        $t->increments('id');
        $t->integer('gid')->default(0);
        foreach (['name', 'servertype', 'configoption1', 'configoption2', 'configoption3', 'configoption4'] as $c) $t->string($c)->default('');
    });
    $schema->create('tblservers', function ($t) {
        $t->increments('id');
        foreach (['name', 'type', 'hostname', 'ipaddress', 'username', 'password', 'accesshash', 'secure', 'port'] as $c) $t->string($c)->default('');
        foreach (['disabled', 'active'] as $c) $t->integer($c)->default(0);
    });
    $schema->create('tblcustomfields', function ($t) {
        $t->increments('id');
        $t->integer('relid')->default(0);
        foreach (['type', 'fieldname', 'fieldtype', 'description', 'fieldoptions', 'regexpr', 'adminonly', 'required', 'showorder', 'showinvoice'] as $c) $t->string($c)->default('');
        $t->integer('sortorder')->default(0);
        $t->timestamps();
    });
    $schema->create('tblcustomfieldsvalues', function ($t) {
        $t->increments('id');
        $t->integer('fieldid');
        $t->integer('relid');
        $t->text('value')->default('');
        $t->timestamps();
    });
    $schema->create('tblconfiguration', function ($t) {
        $t->string('setting')->primary();
        $t->text('value')->default('');
        $t->timestamps();
    });
    $schema->create('tblemailtemplates', function ($t) {
        $t->increments('id');
        foreach (['type', 'name', 'subject', 'language'] as $c) $t->string($c)->default('');
        $t->text('message')->default('');
        foreach (['custom', 'disabled', 'plaintext'] as $c) $t->integer($c)->default(0);
    });

    $GLOBALS['__stand'] = ['activity' => [], 'module' => [], 'localapi' => [], 'hooks' => []];

    function logActivity($message, $userId = 0)
    {
        $GLOBALS['__stand']['activity'][] = (string) $message;
    }

    function logModuleCall($module, $action, $request, $response, $processed = null, $replace = [])
    {
        $GLOBALS['__stand']['module'][] = ['action' => $action, 'request' => $request, 'response' => $response, 'replace' => $replace];
    }

    function encrypt($value)
    {
        return 'enc:' . base64_encode((string) $value);
    }

    function decrypt($value)
    {
        $value = (string) $value;
        return strpos($value, 'enc:') === 0 ? base64_decode(substr($value, 4)) : $value;
    }

    function localAPI($command, $args = [], $admin = '')
    {
        $GLOBALS['__stand']['localapi'][] = [$command, $args];
        if ($command === 'UpdateClientProduct') {
            Manager::table('tblhosting')->where('id', (int) $args['serviceid'])->update(['domainstatus' => $args['status']]);
        }
        return ['result' => 'success'];
    }

    function generate_token($type = 'plain')
    {
        return 'stand-token';
    }

    function check_token($scope = '', $token = '')
    {
        return $token === 'stand-token';
    }

    function add_hook($name, $priority, $callback)
    {
        $GLOBALS['__stand']['hooks'][$name][] = $callback;
    }

    function run_hook(string $name, array $vars = []): void
    {
        foreach ($GLOBALS['__stand']['hooks'][$name] ?? [] as $callback) {
            $callback($vars);
        }
    }
}
