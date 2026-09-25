import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { uploadImage, type UploadImageInput } from '@/api/images';
import { imageKeys } from '@/features/images/queryKeys';

export function useUploadImage() {
  const queryClient = useQueryClient();
  // Fraction of the file sent, from 0 to 1; only meaningful while the mutation is pending.
  const [progress, setProgress] = useState(0);

  const mutation = useMutation({
    mutationFn: (input: UploadImageInput) => {
      setProgress(0);

      return uploadImage(input, { onProgress: setProgress });
    },
    // After a failure, too: a timeout or an unexpected response may follow a stored upload.
    // Not awaited: the form is free again at once, the list catches up in the background.
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: imageKeys.list() });
    },
  });

  return { ...mutation, progress };
}
