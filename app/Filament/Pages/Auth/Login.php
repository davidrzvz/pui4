<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class Login extends BaseLogin
{
    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string | Htmlable
    {
        return 'PUI - Plataforma Única de Identificación';
    }

    public function getSubheading(): string | Htmlable | null
    {
        $institutionName = e(config('pui.institution_name', env('PUI_INSTITUTION_NAME', 'Institución no configurada')));
        $institutionRfc = e(config('pui.institution_rfc', env('PUI_INSTITUTION_RFC', 'RFC no configurado')));

        return new HtmlString(<<<HTML
            <div class="mt-2 text-center">
                <div class="font-semibold text-gray-900 dark:text-gray-100">
                    {$institutionName}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    RFC: {$institutionRfc}
                </div>
            </div>
        HTML);
    }
}