import { useMutation, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { deleteImage } from '@/api/images';
import type { ApiImage } from '@/api/schemas';
import type { ImageListData } from '@/features/images/hooks/useImagesInfinite';
import { imageKeys } from '@/features/images/queryKeys';

interface UseDeleteImageOptions {
  // Runs once the server has deleted the image, just before its card leaves the cache, so the
  // list can still find the card's neighbours (e.g. to move the focus). Set here and not per
  // `mutate()` call: the card that started the deletion unmounts on success, and per-call
  // callbacks of an unmounted component never run.
  onDeleted?: (image: ApiImage) => void;
}

function withoutImage(data: ImageListData | undefined, id: string): ImageListData | undefined {
  if (data === undefined) {
    return undefined;
  }

  // Page params stay as they are: a cursor holds the key values of a row, not a reference to
  // it, so it still points to the right place after that row is deleted.
  return {
    ...data,
    pages: data.pages.map((page) => ({
      ...page,
      data: page.data.filter((image) => image.id !== id),
    })),
  };
}

export function useDeleteImage({ onDeleted }: UseDeleteImageOptions = {}) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (image: ApiImage) => {
      try {
        await deleteImage(image.id);
      } catch (error) {
        // Already gone, e.g. deleted in another tab: exactly what the user asked for.
        if (isAxiosError(error) && error.response?.status === 404) {
          return;
        }
        throw error;
      }
    },
    onSuccess: (_result, image) => {
      const queryKey = imageKeys.list();
      onDeleted?.(image);
      queryClient.setQueryData<ImageListData>(queryKey, (data) => withoutImage(data, image.id));
      // A running list fetch (the next page, or the refetch after an upload) started from the
      // pages before this deletion and would write the image back when it lands. Refetching
      // cancels it and starts over from the updated cache; a cancelled next page is loaded
      // again by the list's sentinel or button. Without one, the list is only marked stale:
      // refetching would reload every loaded page to drop one card.
      const isFetching = queryClient.isFetching({ queryKey }) > 0;
      void queryClient.invalidateQueries({ queryKey, refetchType: isFetching ? 'active' : 'none' });
    },
  });
}
