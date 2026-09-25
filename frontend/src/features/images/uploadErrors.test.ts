import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { describe, expect, it } from 'vitest';
import { imageSchema } from '@/api/schemas';
import { toUploadErrors } from '@/features/images/uploadErrors';

function httpError(status: number, data: unknown): AxiosError {
  const response: AxiosResponse = {
    data,
    status,
    statusText: '',
    headers: {},
    config: { headers: new AxiosHeaders() },
  };

  return new AxiosError(
    'Request failed',
    AxiosError.ERR_BAD_REQUEST,
    undefined,
    undefined,
    response,
  );
}

const MAYBE_STORED = 'The image may have been uploaded anyway; check the list before trying again.';

describe('toUploadErrors', () => {
  it('maps the first message of each known field', () => {
    const error = httpError(422, {
      message: 'The name field is required. (and 2 more errors)',
      errors: {
        uploader_name: ['The name field is required.', 'Second.'],
        uploader_email: ['The e-mail field is required.'],
        file: ['The file field is required.'],
      },
    });

    expect(toUploadErrors(error)).toEqual({
      fields: [
        ['uploader_name', 'The name field is required.'],
        ['uploader_email', 'The e-mail field is required.'],
        ['file', 'The file field is required.'],
      ],
      form: null,
    });
  });

  it('reports messages of unknown fields for the whole form', () => {
    const error = httpError(422, {
      message: 'Invalid.',
      errors: { file: ['Too small.'], other: ['First.', 'Second.'] },
    });

    expect(toUploadErrors(error)).toEqual({
      fields: [['file', 'Too small.']],
      form: 'First. Second.',
    });
  });

  it.each([
    ['no field errors', { message: 'The given data was invalid.', errors: {} }],
    ['only empty lists', { message: 'The given data was invalid.', errors: { file: [] } }],
  ])('falls back to the message of a 422 with %s', (_case, body) => {
    expect(toUploadErrors(httpError(422, body))).toEqual({
      fields: [],
      form: 'The given data was invalid.',
    });
  });

  it('reports a 413 at the file', () => {
    expect(toUploadErrors(httpError(413, '<html>413</html>'))).toEqual({
      fields: [['file', 'The file is too large for the server.']],
      form: null,
    });
  });

  it.each([
    ['a 422 of another shape', 422, { message: 'Invalid.' }],
    ['a server error', 500, { message: 'Server Error' }],
    ['a 404', 404, { message: 'Not found.' }],
  ])('reports %s as a failed upload', (_case, status, body) => {
    expect(toUploadErrors(httpError(status, body))).toEqual({
      fields: [],
      form: 'The upload failed. Please try again.',
    });
  });

  it('reports a network error', () => {
    expect(toUploadErrors(new AxiosError('Network Error', AxiosError.ERR_NETWORK))).toEqual({
      fields: [],
      form: 'Could not reach the server. Check your connection and try again.',
    });
  });

  // The file may have been stored before the client gave up.
  it.each([AxiosError.ECONNABORTED, AxiosError.ETIMEDOUT])('reports a timeout (%s)', (code) => {
    expect(toUploadErrors(new AxiosError('timeout exceeded', code))).toEqual({
      fields: [],
      form: `The upload took too long. ${MAYBE_STORED}`,
    });
  });

  it('reports a response that failed schema parsing', () => {
    expect(toUploadErrors(imageSchema.safeParse({}).error)).toEqual({
      fields: [],
      form: MAYBE_STORED,
    });
  });
});
