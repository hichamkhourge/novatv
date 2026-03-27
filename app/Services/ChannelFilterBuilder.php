<?php

namespace App\Services;

use App\Models\IptvUser;
use Illuminate\Support\Collection;

class ChannelFilterBuilder
{
    /**
     * Build Tuliprox filter string for a user based on their channel selection
     */
    public function buildForUser(IptvUser $user): string
    {
        // Load user's channels with grouping
        $channels = $user->channels()
            ->where('is_active', true)
            ->get();

        // If no channels selected, deny all
        if ($channels->isEmpty()) {
            return 'Group ~ "^$"'; // Match nothing
        }

        // Get unique group names
        $groups = $channels->pluck('group_name')
            ->filter()
            ->unique()
            ->values();

        // If all channels from the source are selected, allow all
        $totalSourceChannels = $user->m3uSource?->channels()->where('is_active', true)->count() ?? 0;
        if ($totalSourceChannels > 0 && $channels->count() === $totalSourceChannels) {
            return 'Group ~ ".*"'; // Match all
        }

        // Build group-based filter
        if ($groups->isNotEmpty()) {
            return $this->buildGroupFilter($groups->toArray());
        }

        // Fallback: build channel name filter (less efficient but works)
        $channelNames = $channels->pluck('name')->toArray();
        return $this->buildChannelNameFilter($channelNames);
    }

    /**
     * Build filter for specific groups
     */
    public function buildGroupFilter(array $groupNames): string
    {
        if (empty($groupNames)) {
            return 'Group ~ "^$"'; // Match nothing
        }

        // Escape special regex characters in group names
        $escapedGroups = array_map(function ($group) {
            return preg_quote($group, '/');
        }, $groupNames);

        // Build regex pattern: match any of these groups
        $pattern = '^(' . implode('|', $escapedGroups) . ')$';

        return "Group ~ \"{$pattern}\"";
    }

    /**
     * Build filter for specific channel names
     */
    public function buildChannelNameFilter(array $channelNames): string
    {
        if (empty($channelNames)) {
            return 'Name ~ "^$"'; // Match nothing
        }

        // Escape special regex characters
        $escapedNames = array_map(function ($name) {
            return preg_quote($name, '/');
        }, $channelNames);

        // Build regex pattern
        $pattern = '^(' . implode('|', $escapedNames) . ')$';

        return "Name ~ \"{$pattern}\"";
    }

    /**
     * Build filter to exclude specific groups
     */
    public function buildExcludeGroupFilter(array $groupNames): string
    {
        if (empty($groupNames)) {
            return 'Group ~ ".*"'; // Match all
        }

        $escapedGroups = array_map(function ($group) {
            return preg_quote($group, '/');
        }, $groupNames);

        $pattern = '(' . implode('|', $escapedGroups) . ')';

        return "Group !~ \"{$pattern}\"";
    }

    /**
     * Combine multiple filters with AND
     */
    public function combineFiltersAnd(array $filters): string
    {
        $filters = array_filter($filters);

        if (empty($filters)) {
            return 'Group ~ ".*"';
        }

        if (count($filters) === 1) {
            return reset($filters);
        }

        return '(' . implode(') AND (', $filters) . ')';
    }

    /**
     * Combine multiple filters with OR
     */
    public function combineFiltersOr(array $filters): string
    {
        $filters = array_filter($filters);

        if (empty($filters)) {
            return 'Group ~ ".*"';
        }

        if (count($filters) === 1) {
            return reset($filters);
        }

        return '(' . implode(') OR (', $filters) . ')';
    }

    /**
     * Build a complex filter with inclusions and exclusions
     */
    public function buildComplexFilter(array $includeGroups, array $excludeGroups = []): string
    {
        $filters = [];

        if (!empty($includeGroups)) {
            $filters[] = $this->buildGroupFilter($includeGroups);
        }

        if (!empty($excludeGroups)) {
            $filters[] = $this->buildExcludeGroupFilter($excludeGroups);
        }

        if (empty($filters)) {
            return 'Group ~ ".*"';
        }

        return $this->combineFiltersAnd($filters);
    }

    /**
     * Parse filter string to extract group names (for reverse operation)
     */
    public function parseGroupFilter(string $filter): array
    {
        // Match pattern: Group ~ "^(group1|group2|group3)$"
        if (preg_match('/Group ~ "\^\\((.+)\\)\$"/', $filter, $matches)) {
            $groupsPattern = $matches[1];
            $groups = explode('|', $groupsPattern);

            // Unescape regex characters
            return array_map(function ($group) {
                return stripslashes($group);
            }, $groups);
        }

        return [];
    }

    /**
     * Check if filter allows all channels
     */
    public function isAllowAll(string $filter): bool
    {
        return in_array(trim($filter), [
            'Group ~ ".*"',
            'Group ~ ".+"',
            'Name ~ ".*"',
            'Name ~ ".+"',
        ]);
    }

    /**
     * Check if filter denies all channels
     */
    public function isDenyAll(string $filter): bool
    {
        return in_array(trim($filter), [
            'Group ~ "^$"',
            'Name ~ "^$"',
        ]);
    }
}
