import { v7 } from 'uuid';

/** Time-ordered UUID v7, so ids sort by creation time. */
export const newId = (): string => v7();
