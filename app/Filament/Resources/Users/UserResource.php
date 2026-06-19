<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Usuarios';
    protected static ?string $modelLabel = 'Usuario';
    protected static ?string $pluralModelLabel = 'Usuarios';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        
        $user = auth()->user();
        if ($user->hasRole('SUPER_ADMIN')) {
            return $query;
        }

        // ADMINISTRADOR ve solo los de su institución
        return $query->where('institution_id', $user->institution_id);
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR']);
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();

        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state)),
                Forms\Components\Select::make('institution_id')
                    ->label('Institución')
                    ->relationship('institution', 'name')
                    ->required(fn () => !$user->hasRole('SUPER_ADMIN'))
                    ->disabled(fn () => !$user->hasRole('SUPER_ADMIN'))
                    ->dehydrated()
                    ->default(fn () => $user->hasRole('SUPER_ADMIN') ? null : $user->institution_id),
                Forms\Components\Select::make('roles')
                    ->label('Rol')
                    ->relationship('roles', 'name', function (Builder $query) use ($user) {
                        if (!$user->hasRole('SUPER_ADMIN')) {
                            $query->whereIn('name', ['ADMINISTRADOR', 'OPERADOR', 'AUDITOR']);
                        }
                    })
                    ->preload()
                    ->searchable()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('institution.name')
                    ->label('Institución')
                    ->sortable()
                    ->placeholder('Global'),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha creación')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUsers::route('/'),
        ];
    }
}
