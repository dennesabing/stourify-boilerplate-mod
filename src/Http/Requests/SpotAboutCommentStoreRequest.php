<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use App\Models\Comment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Models\SpotAbout;

/**
 * Validates a comment written on an About entry.
 *
 * `commentable_type` and `commentable_id` are never accepted from the client.
 * The route already names the entry by its UUID, so the controller sets both
 * from the entry Laravel bound — a client that could name its own host could
 * hang a comment off anything at all.
 *
 * `parent_id` is scoped to the *same* entry: a parent taken from another
 * entry's thread is rejected here rather than silently attaching a reply to the
 * wrong host.
 */
class SpotAboutCommentStoreRequest extends BaseFormRequest
{
    /**
     * Two locks, and both are checked here rather than in the controller.
     *
     * The first asks whether the caller may see this entry at all; the second
     * asks whether they may write comments on an entry, which is the platform's
     * discovered `spot_abouts.comments.create` permission. Neither substitutes
     * for the other — someone who can read the entry but holds no comment
     * permission is refused, and so is someone with every comment permission who
     * cannot see the entry.
     *
     * They sit in `authorize()` because Laravel runs it BEFORE `rules()`.
     * Checked in the controller instead, an unauthorized caller would first be
     * handed a 422 itemising the fields the server wanted, and would reach 403
     * only if their payload happened to validate. That ordering defect is
     * STOURIFY-23; `SpotAboutStoreRequest` carries the same override for it.
     */
    public function authorize(): bool
    {
        $about = $this->route('about');
        $user = $this->user();

        if (! $about instanceof SpotAbout || $user === null) {
            return false;
        }

        return $user->can('view', $about)
            && $user->can('create', [Comment::class, $about]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var SpotAbout $about */
        $about = $this->route('about');

        return [
            'body' => ['required', 'string', 'min:1', 'max:2000'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('comments', 'id')
                    ->where('commentable_type', SpotAbout::class)
                    ->where('commentable_id', $about->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'That comment does not belong to this About entry.',
        ];
    }
}
