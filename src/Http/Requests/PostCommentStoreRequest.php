<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Models\Post;

/**
 * Validates a comment written on a post.
 *
 * `commentable_type`/`commentable_id` are never accepted from the client —
 * this route is nested under the post's own uuid, so the controller sets
 * both from the bound `Post`. `parent_id` is scoped to the *same* post: a
 * parent from another post's thread is rejected here rather than silently
 * attaching a reply to the wrong host.
 */
class PostCommentStoreRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Post $post */
        $post = $this->route('post');

        return [
            'body' => ['required', 'string', 'min:1'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('comments', 'id')
                    ->where('commentable_type', Post::class)
                    ->where('commentable_id', $post->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'That comment does not belong to this post.',
        ];
    }
}
