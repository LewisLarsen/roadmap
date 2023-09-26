<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Filament\Resources\ItemResource;
use App\Filament\Resources\UserResource;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Rules\ProfanityCheck;
use App\Settings\GeneralSettings;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Livewire\Component;
use Filament\Actions\Action;

class Header extends Component implements HasForms, HasActions
{
    use InteractsWithForms, InteractsWithActions;

    public $logo;
    public $projects;

    public function mount()
    {
        $this->projects = Project::query()
            ->visibleForCurrentUser()
            ->when(app(GeneralSettings::class)->show_projects_sidebar_without_boards === false, function ($query) {
                return $query->has('boards');
            })
            ->orderBy('sort_order')
            ->orderBy('group')
            ->orderBy('title')
            ->get();
    }

    public function render()
    {
        return view('livewire.header');
    }

    public function submitItemAction(): Action
    {
        return Action::make('submitItem')
            ->requiresConfirmation()
//            ->color(Color::hex('#ffffff'))
            ->icon('heroicon-o-plus-circle')
            ->modalAlignment(Alignment::Left)
            ->modalIcon('heroicon-o-plus-circle')
            ->modalWidth('3xl')
            ->form(function () {
                $inputs = [];

                $inputs[] = TextInput::make('title')
                    ->autofocus()
                    ->rules([
                        new ProfanityCheck()
                    ])
                    ->label(trans('table.title'))
                    ->lazy()
                    ->afterStateUpdated(function (\Filament\Forms\Set $set, $state) {
                        $this->setSimilarItems($state);
                    })
                    ->minLength(3)
                    ->required();

                if (app(GeneralSettings::class)->select_project_when_creating_item) {
                    $inputs[] = Select::make('project_id')
                        ->label(trans('table.project'))
                        ->reactive()
                        ->options(Project::query()->visibleForCurrentUser()->pluck('title', 'id'))
                        ->required(app(GeneralSettings::class)->project_required_when_creating_item);
                }

                if (app(GeneralSettings::class)->select_board_when_creating_item) {
                    $inputs[] = Select::make('board_id')
                        ->label(trans('table.board'))
                        ->visible(fn($get) => $get('project_id'))
                        ->options(fn($get) => Project::find($get('project_id'))->boards()->pluck('title', 'id'))
                        ->required(app(GeneralSettings::class)->board_required_when_creating_item);
                }

                $inputs[] = Group::make([
                    MarkdownEditor::make('content')
                        ->label(trans('table.content'))
                        ->rules([
                            new ProfanityCheck()
                        ])
                        ->disableToolbarButtons(app(GeneralSettings::class)->getDisabledToolbarButtons())
                        ->minLength(10)
                        ->required()
                ]);

                return $inputs;
            })
            ->action(function (array $data) {
                if (!auth()->user()) {
                    return redirect()->route('login');
                }

                if (app(GeneralSettings::class)->users_must_verify_email && !auth()->user()->hasVerifiedEmail()) {
                    Notification::make('must_verify')
                        ->title('Verification')
                        ->body('Please verify your email before submitting items.')
                        ->danger()
                        ->send();

                    return redirect()->route('verification.notice');
                }

                $item = Item::create([
                    'title' => $data['title'],
                    'content' => $data['content'],
                    'project_id' => $data['project_id'] ?? null
                ]);

                $item->user()->associate(auth()->user())->save();

                $item->toggleUpvote();

                Notification::make('must_verify')
                    ->title('Item')
                    ->body(trans('items.item_created'))
                    ->success()
                    ->send();

                if (config('filament.database_notifications.enabled')) {
                    User::query()->whereIn('role', [UserRole::Admin->value, UserRole::Employee->value])->each(function (User $user) use ($item) {
                        Notification::make()
                            ->title(trans('items.item_created'))
                            ->body(trans('items.item_created_notification_body', ['user' => auth()->user()->name, 'title' => $item->title]))
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('view')->label(trans('notifications.view-item'))->url(ItemResource::getUrl('edit', ['record' => $item])),
                                Action::make('view_user')->label(trans('notifications.view-user'))->url(UserResource::getUrl('edit', ['record' => auth()->user()])),
                            ])
                            ->sendToDatabase($user);
                    });
                }

                $this->redirectRoute('items.show', $item->slug);
            });
    }

    public function setSimilarItems($state): void
    {
        // TODO:
        // At some point we're going to want to exclude (filter from the array) common words (that should probably be configurable by the user)
        // or having those common words inside the translation file, preference is to use the settings plugin
        // we already have, so that the administrators can put in common words.
        //
        // Common words example: the, it, that, when, how, this, true, false, is, not, well, with, use, enable, of, for
        // ^ These are words you don't want to search on in your database and exclude from the array.
        $words = collect(explode(' ', $state))->filter(function ($item) {
            $excludedWords = app(GeneralSettings::class)->excluded_matching_search_words;

            return !in_array($item, $excludedWords);
        });

        $this->similarItems = $state ? Item::query()
            ->visibleForCurrentUser()
            ->where(function ($query) use ($words) {
                foreach ($words as $word) {
                    $query->orWhere('title', 'like', '%' . $word . '%');
                }

                return $query;
            })->get(['title', 'slug']) : collect([]);
    }
}
