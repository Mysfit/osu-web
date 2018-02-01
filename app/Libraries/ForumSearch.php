<?php

/**
 *    Copyright 2015-2017 ppy Pty. Ltd.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Libraries;

use App\Libraries\Elasticsearch\SearchResults;
use App\Models\Forum\Forum;
use App\Models\Forum\Post;
use App\Models\Forum\Topic;
use App\Models\User;
use Carbon\Carbon;
use Es;

class ForumSearch
{
    public static function buildQuery(
        string $queryString,
        string $bool = 'must',
        ?string $type = null
    ) : array {
        $query = [
            'bool' => [
                'should' => [],
                'must' => [],
                'must_not' => [],
                'filter' => [],
            ],
        ];

        $query['bool'][$bool][] = [
            'query_string' => [
                'fields' => ['post_preview', 'title'],
                'query' => $queryString,
            ],
        ];

        if ($type !== null) {
            $query['bool']['filter'][] = [
                ['term' => ['type' => $type]],
            ];
        }

        return $query;
    }

    public static function childQuery(string $queryString) : array
    {
        return [
            'type' => 'posts',
            'score_mode' => 'max',
            'inner_hits' => [
                '_source' => ['topic_id', 'post_id', 'post_preview'],
                'name' => 'posts',
                'size' => 3,
                'highlight' => [
                    'fields' => [
                        'post_preview' => new \stdClass(),
                    ],
                ],
            ],
            'query' => static::buildQuery($queryString, 'must'),
        ];
    }

    public static function firstPostQuery() : array
    {
        return [
            'type' => 'posts',
            'score_mode' => 'none',
            'inner_hits' => [
                '_source' => 'post_preview',
                'name' => 'first_post',
                'size' => 1,
                'sort' => [['post_id' => ['order' => 'asc']]],
            ],
            'query' => ['match_all' => new \stdClass()],
        ];
    }

    public static function search(string $queryString, array $options = []) : array
    {
        // FIXME: extract all the page-limit mapping junk away
        $page = max(1, $options['page'] ?? 1);
        $size = clamp($options['size'] ?? $options['limit'] ?? 50, 1, 50);
        $from = ($page - 1) * $size;

        $forumId = get_int($options['forum_id'] ?? null);
        $includeChildren = get_bool($options['forum_children'] ?? false);
        $posterName = presence($options['username'] ?? null);

        $query = static::buildQuery($queryString, 'should', 'topics');
        $query['bool']['minimum_should_match'] = 1;
        $childQuery = static::childQuery($queryString, $forumId);

        if ($posterName !== null) {
            $user = User::where('username', '=', $posterName)->first();
            $userQuery = ['term' => ['user_id' => $user ? $user->user_id : -1]];

            $childQuery['query']['bool']['filter'][] = ['term' => ['poster_id' => $user ? $user->user_id : -1]];
        }

        $query['bool']['should'][] = ['has_child' => $childQuery];

        if ($forumId !== null) {
            $forumIds = $includeChildren ? Forum::findOrFail($forumId)->allSubForums() : [$forumId];
            $forumQuery = ['terms' => ['forum_id' => $forumIds]];

            $query['bool']['filter'][] = $forumQuery;
        }

        $query['bool']['must'][] = ['has_child' => static::firstPostQuery()];

        $body = [
            'highlight' => ['fields' => ['title' => new \stdClass()]],
            'size' => $size,
            'from' => $from,
            'query' => $query,
        ];

        return [
            new SearchResults(
                Es::search([
                    'index' => Post::esIndexName(),
                    'body' => $body,
                ]),
                'posts'
            ),
            ['limit' => $size, 'page' => $page],
        ];
    }
}
