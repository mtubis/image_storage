import { zodResolver } from '@hookform/resolvers/zod';
import { useId, useRef, useState, type ReactNode } from 'react';
import { useController, useForm } from 'react-hook-form';
import { useUploadImage } from '@/features/images/hooks/useUploadImage';
import { toUploadErrors, UPLOAD_FIELDS } from '@/features/images/uploadErrors';
import {
  ACCEPT,
  ALLOWED_TYPES_LABEL,
  MAX_FILE_SIZE_BYTES,
  MIN_HEIGHT,
  MIN_WIDTH,
} from '@/features/images/uploadConstraints';
import {
  uploadFormSchema,
  type UploadFormInput,
  type UploadFormValues,
} from '@/features/images/uploadFormSchema';
import { formatBytes } from '@/lib/formatBytes';
import { readImageDimensions } from '@/lib/readImageDimensions';
import styles from './UploadForm.module.css';

export function UploadForm() {
  const headingId = useId();
  const upload = useUploadImage();
  const [uploadedName, setUploadedName] = useState<string | null>(null);
  const {
    control,
    register,
    handleSubmit,
    reset,
    setError,
    setFocus,
    trigger,
    formState: { errors, isSubmitting },
  } = useForm<UploadFormInput, unknown, UploadFormValues>({
    resolver: zodResolver(uploadFormSchema),
    // No file: the schema reports the missing selection.
    defaultValues: { uploader_name: '', uploader_email: '' },
    // RHF would focus in registration order, where the file (useController) comes first.
    shouldFocusError: false,
  });
  const { field: fileField } = useController({ control, name: 'file' });
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const focusFirstError = (fields: readonly string[]) => {
    const first = UPLOAD_FIELDS.find((name) => fields.includes(name));
    if (first !== undefined) {
      setFocus(first);
    }
  };

  // Checked right away: a wrong file is better reported before the user submits. The validation
  // only starts once the dimensions are read (and cached), and it validates the file chosen by
  // then; started at once, the slower check of an earlier file would report over a later one.
  const validateChosenFile = async (file: File | undefined) => {
    if (file !== undefined) {
      await readImageDimensions(file);
    }
    await trigger('file');
  };

  const onSubmit = async (values: UploadFormValues) => {
    setUploadedName(null);
    try {
      const image = await upload.mutateAsync(values);
      reset();
      // A file input can't be controlled; reset() only clears the form's value.
      if (fileInputRef.current !== null) {
        fileInputRef.current.value = '';
      }
      setUploadedName(image.original_name);
    } catch (error) {
      const { fields, form } = toUploadErrors(error);
      for (const [name, message] of fields) {
        setError(name, { type: 'server', message });
      }
      focusFirstError(fields.map(([name]) => name));
      if (form !== null) {
        setError('root.server', { type: 'server', message: form });
      }
    }
  };

  return (
    <section className={styles.section} aria-labelledby={headingId}>
      <h2 id={headingId} className={styles.heading}>
        Upload an image
      </h2>
      <form
        className={styles.form}
        // The schema validates; the browser's own bubbles would show different messages.
        noValidate
        onSubmit={(event) => {
          // The button stays focusable while uploading (aria-disabled), so guard here.
          if (isSubmitting) {
            event.preventDefault();

            return;
          }
          void handleSubmit(onSubmit, (invalid) => {
            focusFirstError(Object.keys(invalid));
          })(event);
        }}
      >
        <p className={styles.hint}>All fields are required.</p>
        <Field label="Your name" error={errors.uploader_name?.message}>
          {(describedBy) => (
            <input
              type="text"
              autoComplete="name"
              aria-required
              aria-invalid={errors.uploader_name !== undefined}
              aria-describedby={describedBy}
              {...register('uploader_name')}
            />
          )}
        </Field>
        <Field label="Your e-mail" error={errors.uploader_email?.message}>
          {(describedBy) => (
            <input
              type="email"
              autoComplete="email"
              aria-required
              aria-invalid={errors.uploader_email !== undefined}
              aria-describedby={describedBy}
              {...register('uploader_email')}
            />
          )}
        </Field>
        <Field
          label="Image"
          hint={`${ALLOWED_TYPES_LABEL}, up to ${formatBytes(MAX_FILE_SIZE_BYTES)}, at least ${String(MIN_WIDTH)} × ${String(MIN_HEIGHT)} px.`}
          error={errors.file?.message}
          // Checked on selection, while the focus stays on the input: announced, not just shown.
          announceError
        >
          {(describedBy) => (
            <input
              type="file"
              accept={ACCEPT}
              aria-required
              name={fileField.name}
              aria-invalid={errors.file !== undefined}
              aria-describedby={describedBy}
              ref={(element) => {
                fileField.ref(element);
                fileInputRef.current = element;
              }}
              onBlur={fileField.onBlur}
              onChange={(event) => {
                const file = event.target.files?.[0];
                fileField.onChange(file);
                void validateChosenFile(file);
              }}
            />
          )}
        </Field>
        {errors.root?.server !== undefined && (
          <p className={styles.error} role="alert">
            {errors.root.server.message}
          </p>
        )}
        <div className={styles.actions}>
          <button type="submit" className={styles.button} aria-disabled={isSubmitting}>
            {buttonLabel(isSubmitting, upload.isPending, upload.progress)}
          </button>
          {upload.isPending && (
            <progress
              className={styles.progress}
              aria-label="Upload progress"
              max={1}
              value={upload.progress}
            />
          )}
          {/* Always rendered: screen readers only announce changes of an existing live region. */}
          <p className={styles.success} role="status">
            {uploadedName !== null && `Uploaded ${uploadedName}.`}
          </p>
        </div>
      </form>
    </section>
  );
}

function buttonLabel(isSubmitting: boolean, isPending: boolean, progress: number): string {
  if (!isPending) {
    return isSubmitting ? 'Checking…' : 'Upload';
  }

  // All bytes sent: the server now extracts the metadata and renders the thumbnail.
  return progress < 1 ? 'Uploading…' : 'Processing…';
}

interface FieldProps {
  label: string;
  hint?: string;
  error: string | undefined;
  // Puts the error into a live region, for errors that appear without a submit.
  announceError?: boolean;
  // Renders the control, given the ids of the hint and the error for aria-describedby.
  children: (describedBy: string | undefined) => ReactNode;
}

function Field({ label, hint, error, announceError = false, children }: FieldProps) {
  const hintId = useId();
  const errorId = useId();
  const describedBy =
    [hint === undefined ? null : hintId, error === undefined ? null : errorId]
      .filter((id) => id !== null)
      .join(' ') || undefined;

  return (
    // A wrapping label names the control without the id plumbing.
    <div className={styles.field}>
      <label>
        <span className={styles.label}>{label}</span>
        {children(describedBy)}
      </label>
      {hint !== undefined && (
        <p id={hintId} className={styles.hint}>
          {hint}
        </p>
      )}
      {/* The live region must exist before its content changes to be announced. */}
      <div aria-live={announceError ? 'polite' : undefined}>
        {error !== undefined && (
          <p id={errorId} className={styles.error}>
            {error}
          </p>
        )}
      </div>
    </div>
  );
}
