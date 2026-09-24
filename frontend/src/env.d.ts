// Augments the ImportMetaEnv declared by vite/client. Values are untrusted at
// runtime (a missing variable is `undefined`), so they are parsed on startup.
interface ImportMetaEnv {
  readonly VITE_API_URL: string;
}
