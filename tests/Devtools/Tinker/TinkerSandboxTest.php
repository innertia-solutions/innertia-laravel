<?php

use Innertia\Devtools\Tinker\TinkerSandbox;

it('allows safe code', function () {
    expect(fn () => TinkerSandbox::validate('$x = 1 + 1;'))->not->toThrow(RuntimeException::class);
    expect(fn () => TinkerSandbox::validate('User::all();'))->not->toThrow(RuntimeException::class);
    expect(fn () => TinkerSandbox::validate('DB::table("users")->count();'))->not->toThrow(RuntimeException::class);
});

it('blocks exec', function () {
    expect(fn () => TinkerSandbox::validate('exec("ls")'))->toThrow(RuntimeException::class, "'exec'");
});

it('blocks shell_exec', function () {
    expect(fn () => TinkerSandbox::validate('$r = shell_exec("id");'))->toThrow(RuntimeException::class, "'shell_exec'");
});

it('blocks system', function () {
    expect(fn () => TinkerSandbox::validate('system("whoami");'))->toThrow(RuntimeException::class, "'system'");
});

it('blocks file_put_contents', function () {
    expect(fn () => TinkerSandbox::validate('file_put_contents("/etc/cron.d/x", "evil");'))
        ->toThrow(RuntimeException::class, "'file_put_contents'");
});

it('blocks unlink', function () {
    expect(fn () => TinkerSandbox::validate('unlink("/important/file");'))->toThrow(RuntimeException::class, "'unlink'");
});

it('blocks proc_open', function () {
    expect(fn () => TinkerSandbox::validate('proc_open("bash", [], $p);'))->toThrow(RuntimeException::class, "'proc_open'");
});
