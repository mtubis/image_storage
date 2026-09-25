import { useInfiniteQuery, type InfiniteData } from '@tanstack/react-query';
import { fetchImagePage } from '@/api/images';
import type { ApiImage, ImagePage } from '@/api/schemas';
import { imageKeys } from '@/features/images/queryKeys';

// The cached shape of the list: pages keyed by their cursor (null for the first page).
export type ImageListData = InfiniteData<ImagePage, string | null>;

// Module-level, so TanStack Query only re-runs it when the pages change.
function flattenPages(data: ImageListData): ApiImage[] {
  return data.pages.flatMap((page) => page.data);
}

export function useImagesInfinite() {
  return useInfiniteQuery({
    queryKey: imageKeys.list(),
    queryFn: ({ pageParam, signal }) => fetchImagePage(pageParam, signal),
    initialPageParam: null as string | null,
    // null on the last page, which is also how TanStack Query marks "no next page".
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
    select: flattenPages,
  });
}
