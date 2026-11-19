<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Innertia\Mail\Branding;
use Innertia\Mail\MailSettings;
use Innertia\Mail\NotificationMail;
use Innertia\Mail\TenantBranding;

uses(RefreshDatabase::class);

beforeEach(function () {
    noticeSetUp();
    config()->set('app.name', 'pomely');
    config()->set('innertia.mail.brand_color', '#112233');
    config()->set('innertia.mail.logo_url', null);
});

it('without settings the brand is exactly the legacy config brand', function () {
    expect(Branding::platform()->toArray())->toBe(Branding::fromConfig()->toArray())
        ->and(Branding::current()->color)->toBe('#112233')
        ->and(Branding::for()->name)->toBe('pomely');
});

it('the layout renders like before when no branding is set', function () {
    $html = NotificationMail::make()->withSubject('Hola')->title('Título')->action('Ver', 'https://x.test')->render();

    expect($html)->toContain('#112233')->toContain('pomely')->not->toContain('Enviado vía');
});

it('the platform brand comes from the platform mail settings', function () {
    MailSettings::save(['brand_color' => '#abcdef', 'logo_url' => 'https://cdn.test/logo.png']);

    $b = Branding::platform();
    expect($b->color)->toBe('#abcdef')->and($b->logoUrl)->toBe('https://cdn.test/logo.png');
});

it('the layout accepts an explicit $branding', function () {
    $html = view('innertia::components.mail.layout', [
        'branding' => new Branding(name: 'Rancagua', color: '#ff0000', footerPhone: '+56 72 123', via: 'pomely'),
        'slot'     => new \Illuminate\Support\HtmlString('cuerpo'),
        'title'    => null, 'preview' => null,
    ])->render();

    expect($html)->toContain('Rancagua')->toContain('+56 72 123')->toContain('Enviado vía pomely');
});

describe('tenant branding (open mode)', function () {
    beforeEach(function () {
        bootOrgOpenMode($this->app);
        \Illuminate\Support\Facades\Schema::create('settings', function ($t) {
            $t->id();
            $t->string('tenant_id')->nullable();
            $t->string('key');
            $t->string('value_type')->default('string');
            $t->text('value')->nullable();
            $t->boolean('is_encrypted')->default(false);
            $t->timestamps();
        });
        MailSettings::flush();
        config()->set('innertia.mail.verify_logo', true);
    });

    it('resolves the tenant brand with field-by-field fallback to the platform', function () {
        Http::fake(['cdn.test/*' => Http::response('', 200, ['Content-Type' => 'image/png'])]);
        $tenantId = (string) \Innertia\Facades\Innertia::tenant()->getKey();
        TenantBranding::save($tenantId, ['display_name' => 'CESFAM Rancagua', 'logo_url' => 'https://cdn.test/rancagua.png', 'footer_address' => 'Av. Siempre Viva 123']);

        $b = Branding::for($tenantId);

        expect($b->name)->toBe('CESFAM Rancagua')
            ->and($b->logoUrl)->toBe('https://cdn.test/rancagua.png')
            ->and($b->color)->toBe('#112233')
            ->and($b->footerAddress)->toBe('Av. Siempre Viva 123')
            ->and($b->via)->toBe('pomely');

        $html = NotificationMail::make()->withSubject('Hola')->title('T')->forTenant($tenantId)->render();
        expect($html)->toContain('CESFAM Rancagua')->toContain('Enviado vía pomely');
    });

    it('an unreachable logo is dropped (name as text, never a broken image)', function () {
        Http::fake(['cdn.test/*' => Http::response('', 404)]);
        $tenantId = (string) \Innertia\Facades\Innertia::tenant()->getKey();
        TenantBranding::save($tenantId, ['display_name' => 'Rancagua', 'logo_url' => 'https://cdn.test/dead.png']);

        expect(Branding::for($tenantId)->logoUrl)->toBeNull();
    });

    it('a tenant without brand gets the platform brand', function () {
        expect(Branding::for()->toArray())->toBe(Branding::platform()->toArray());
    });
});
