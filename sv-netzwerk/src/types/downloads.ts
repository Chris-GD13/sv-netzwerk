export type DownloadStatus = 'draft' | 'review' | 'published' | 'archived';

export interface DownloadRecord {
  id: string;
  title: string;
  description: string;
  category: string;
  tags: string[];
  file: string;
  fileType: string;
  fileSize?: string;
  version?: string;
  audience?: string[];
  updatedAt?: Date;
  publishedAt: Date;
  status: DownloadStatus;
}
