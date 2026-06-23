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
        $institutionName = e(config('pui.institution_name', 'Institución no configurada'));
        $institutionRfc = e(config('pui.institution_rfc', 'RFC no configurado'));

        return new HtmlString(<<<HTML
            <div class="mb-6">

                <div class="text-center mb-5">
                    <div class="text-xs font-semibold tracking-[0.35em] text-primary-500 uppercase mb-2">
                        PUI
                    </div>

                    <div class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Plataforma Única de Identificación
                    </div>
                </div>


                <div class="
                    rounded-xl 
                    border 
                    border-gray-200 
                    dark:border-white/10
                    bg-gray-50 
                    dark:bg-white/[0.04]
                    px-5 
                    py-4
                    mb-5
                ">
                    <div class="flex items-start gap-3">

                        <div class="
                            flex h-10 w-10 shrink-0 items-center justify-center
                            rounded-lg
                            bg-primary-500/10
                            text-primary-500
                            text-sm
                            font-bold
                        ">
                            🏢
                        </div>


                        <div class="text-left">

                            <div class="
                                text-sm 
                                font-medium 
                                leading-5
                                text-gray-900 
                                dark:text-gray-100
                            ">
                                {$institutionName}
                            </div>


                            <div class="
                                mt-2
                                inline-flex
                                rounded-md
                                bg-gray-200
                                dark:bg-gray-800
                                px-2
                                py-1
                                text-xs
                                font-medium
                                text-gray-600
                                dark:text-gray-400
                            ">
                                RFC {$institutionRfc}
                            </div>

                        </div>

                    </div>
                </div>


                <div class="
                    text-center 
                    text-lg 
                    font-semibold
                    text-gray-900
                    dark:text-white
                ">
                    Acceso al sistema
                </div>

            </div>
        HTML);
    }
}