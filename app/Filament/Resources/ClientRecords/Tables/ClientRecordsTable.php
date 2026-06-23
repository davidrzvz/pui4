<?php

namespace App\Filament\Resources\ClientRecords\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;

class ClientRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('institution.name')
                    ->label('Institución')
                    ->visible(fn () => auth()->user()->hasRole('SUPER_ADMIN'))
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('curp')
                    ->label('CURP')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('internal_identifier')
                    ->label('ID Interno')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                \Filament\Tables\Columns\BadgeColumn::make('is_active')
                    ->label('Estado')
                    ->colors([
                        'success' => fn ($state) => $state === true,
                        'secondary' => fn ($state) => $state === false,
                    ])
                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo'),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de alta')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos (incluir inactivos)')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                \Filament\Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
