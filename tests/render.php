<?php

// Compile and render actual Blade UI against fixtures; no NexusPHP application bootstrap.
function csrf_token() { return 'isolated-preview-token'; }
require $argv[1];
$root = dirname(__DIR__);
$output = $argv[2];
if (!is_dir($output)) { mkdir($output, 0755, true); }
$files = new \Illuminate\Filesystem\Filesystem();
$compiler = new \Illuminate\View\Compilers\BladeCompiler($files, $output);
$resolver = new \Illuminate\View\Engines\EngineResolver();
$resolver->register('blade', fn () => new \Illuminate\View\Engines\CompilerEngine($compiler));
$resolver->register('php', fn () => new \Illuminate\View\Engines\PhpEngine($files));
$finder = new \Illuminate\View\FileViewFinder($files, [$root . '/resources/views']);
$events = new \Illuminate\Events\Dispatcher();
$factory = new \Illuminate\View\Factory($resolver, $finder, $events);
$container = new \Illuminate\Container\Container();
$container->instance('view', $factory);
$factory->setContainer($container);
\Illuminate\Container\Container::setInstance($container);
$data = ['torrent' => (object) ['id' => 123, 'name' => 'Small Town Remedy 2026 S01 2160p WEB-DL H265'],
    'promotion' => null, 'ranking' => [], 'user' => (object) ['id' => 1, 'seedbonus' => 999999],
    'requestKey' => str_repeat('a', 64), 'minHours' => 1, 'hourCost' => '2500.00', 'enabled' => true];
file_put_contents($output . '/purchase.html', $factory->make('modal', $data)->render());
$data['promotion'] = (object) ['id' => 1, 'sp_state' => 4, 'settled_at' => null, 'ends_at' => date('Y-m-d H:i:s', time() + 86400), 'user_id' => 1, 'bid' => '500.00', 'reward' => '1000.00', 'winners' => 3];
$data['ranking'] = [(object) ['username' => '<test-user>', 'uploaded' => 10737418240, 'estimated' => '750.00', 'paid' => '0.00'], (object) ['username' => 'user-2', 'uploaded' => 3579139413, 'estimated' => '250.00', 'paid' => '0.00']];
file_put_contents($output . '/append.html', $factory->make('modal', $data)->render());
$data['user']->id = 2;
file_put_contents($output . '/participant.html', $factory->make('modal', $data)->render());
$data['promotion']->settled_at = date('Y-m-d H:i:s');
file_put_contents($output . '/settled.html', $factory->make('modal', $data)->render());
echo "Rendered purchase, append, participant and settled Blade states.\n";
