<?php

namespace App\Filament\Resources\ClientRecords\Schemas;

use Filament\Schemas\Schema;

class ClientRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Select::make('institution_id')
                    ->label('Institución')
                    ->relationship('institution', 'name')
                    ->visible(fn () => auth()->user()->hasRole('SUPER_ADMIN'))
                    ->required(fn () => auth()->user()->hasRole('SUPER_ADMIN'))
                    ->dehydrated(fn () => auth()->user()->hasRole('SUPER_ADMIN')),

                \Filament\Forms\Components\TextInput::make('curp')
                    ->label('CURP')
                    ->required()
                    ->regex('/^[A-Z0-9]{18}$/')
                    ->validationMessages([
                        'regex' => 'CURP inválida. Debe contener exactamente 18 caracteres usando únicamente letras mayúsculas y números.',
                    ])
                    ->maxLength(18),

                \Filament\Forms\Components\TextInput::make('internal_identifier')
                    ->label('Identificador interno')
                    ->required()
                    ->maxLength(255),

                \Filament\Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->maxLength(255),

                \Filament\Forms\Components\KeyValue::make('additional_data')
                    ->label('Datos adicionales existentes')
                    ->columnSpanFull(),
            ]);
    }
}
