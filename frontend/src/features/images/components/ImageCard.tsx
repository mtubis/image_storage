import { useId, useState } from 'react';
import type { ApiImage } from '@/api/schemas';
import { formatBytes } from '@/lib/formatBytes';
import styles from './ImageCard.module.css';

interface ImageCardProps {
  image: ApiImage;
}

export function ImageCard({ image }: ImageCardProps) {
  const titleId = useId();
  const [thumbnailFailed, setThumbnailFailed] = useState(false);

  return (
    <article className={styles.card} aria-labelledby={titleId}>
      {/* Fixed square box: the thumbnail's aspect ratio can't shift the grid while it loads. */}
      <div className={styles.thumbnail}>
        {thumbnailFailed ? (
          <span
            className={styles.placeholder}
            role="img"
            aria-label={`No preview of ${image.original_name}`}
          >
            Preview unavailable
          </span>
        ) : (
          <img
            src={image.thumbnail_url}
            alt={`Thumbnail of ${image.original_name}`}
            loading="lazy"
            decoding="async"
            onError={() => {
              setThumbnailFailed(true);
            }}
          />
        )}
      </div>
      <div className={styles.body}>
        {/* Long names are cut after two lines; `title` shows the whole one on hover. */}
        <h3 id={titleId} className={styles.name} title={image.original_name}>
          {image.original_name}
        </h3>
        <dl className={styles.details}>
          <div>
            <dt>Type</dt>
            <dd>{image.extension.toUpperCase()}</dd>
          </div>
          <div>
            <dt>Size</dt>
            <dd>{formatBytes(image.size_bytes)}</dd>
          </div>
          <div>
            <dt>Resolution</dt>
            <dd>
              {image.width} × {image.height} px
            </dd>
          </div>
          <div>
            <dt>Uploaded by</dt>
            <dd className={styles.uploader}>{image.uploader_name}</dd>
          </div>
        </dl>
      </div>
    </article>
  );
}
