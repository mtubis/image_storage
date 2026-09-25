import { AppLayout } from '@/components/AppLayout';
import { ImageList } from '@/features/images/components/ImageList';
import { UploadForm } from '@/features/images/components/UploadForm';

export function App() {
  return (
    <AppLayout>
      <UploadForm />
      <ImageList />
    </AppLayout>
  );
}
