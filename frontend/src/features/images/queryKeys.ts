// The single definition of the image cache keys, shared by the list query and the upload
// and delete mutations.
export const imageKeys = {
  all: ['images'] as const,
  list: () => [...imageKeys.all, 'list'] as const,
};
