<?php

namespace App\Filament\Resources\Institutions;

use App\Filament\Resources\Institutions\Pages;
use App\Models\Institution;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InstitutionResource extends Resource
{
    protected static ?string $model = Institution::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Instituciones';
    protected static ?string $modelLabel = 'Institución';
    protected static ?string $pluralModelLabel = 'Instituciones';

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('SUPER_ADMIN');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos Principales')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre de institución')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('rfc')
                            ->label('RFC')
                            ->required()
                            ->maxLength(13)
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                            ->dehydrateStateUsing(fn ($state) => strtoupper($state)),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Activa/Inactiva')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('Credenciales PUI')
                    ->schema([
                        Forms\Components\TextInput::make('pui_credentials.api_url')
                            ->label('URL base API Gobierno')
                            ->required()
                            ->url()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('pui_credentials.pui_user')
                            ->label('Usuario PUI / Institución ID')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('pui_credentials.pui_password')
                            ->label('Clave PUI')
                            ->password()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn ($state) => !empty($state) ? encrypt($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->hiddenOn('view'),
                        Forms\Components\TextInput::make('pui_credentials.api_version')
                            ->label('Versión API')
                            ->default('2_3_0')
                            ->required(),
                        Forms\Components\Select::make('pui_credentials.environment')
                            ->label('Ambiente')
                            ->options([
                                'producción' => 'Producción',
                                'pruebas' => 'Pruebas',
                            ])
                            ->default('pruebas')
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rfc')
                    ->label('RFC')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Estado')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha creación')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageInstitutions::route('/'),
        ];
    }
}
