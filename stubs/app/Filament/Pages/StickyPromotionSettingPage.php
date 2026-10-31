<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use NexusPlugin\StickyPromotion\Repository;

class StickyPromotionSettingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-megaphone';
    protected static string | \UnitEnum | null $navigationGroup = 'System';
    protected static ?int $navigationSort = 13;
    protected string $view = 'sticky-promotion::admin';
    public ?array $data = [];

    public static function getNavigationLabel(): string { return '置顶促销'; }
    public function getTitle(): string { return '置顶促销设置'; }
    public static function canAccess(): bool
    {
        return Auth::guard('nexus-web')->user()?->canAccessAdmin() === true
            && class_exists(Repository::class) && \Nexus\Plugin\Plugin::getById(Repository::ID) !== null;
    }
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->content->fill(Setting::get('sticky_promotion', ['enabled' => 'yes', 'base' => 100, 'min_hours' => 1, 'min_class' => 0]));
        $this->data = array_replace(array_combine(
            array_map(fn ($state) => 'multiplier_' . $state, array_keys(\NexusPlugin\StickyPromotion\Service::DEFAULT_MULTIPLIERS)),
            array_values(\NexusPlugin\StickyPromotion\Service::DEFAULT_MULTIPLIERS)
        ), $this->data ?? []);
    }
    public function content(Schema $schema): Schema
    {
        return $schema->components([...Repository::fields(), Action::make('save')->label('保存')->action(fn () => $this->submit())])->statePath('data');
    }
    public function submit(): void
    {
        abort_unless(static::canAccess(), 403);
        foreach ($this->content->getState() as $key => $value) {
            Setting::query()->updateOrCreate(['name' => 'sticky_promotion.' . $key], ['value' => (string) $value, 'autoload' => 'yes']);
        }
        clear_setting_cache();
        send_admin_success_notification();
    }
}
