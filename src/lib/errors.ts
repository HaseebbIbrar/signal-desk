export class AppError extends Error {
  readonly status: number;
  readonly code: string;
  constructor(status: number, code: string, message: string) {
    super(message);
    this.status = status;
    this.code = code;
    this.name = new.target.name;
  }
}

export class ValidationError extends AppError {
  constructor(message: string) {
    super(400, "VALIDATION", message);
  }
}

export class NotFoundError extends AppError {
  constructor(message: string) {
    super(404, "NOT_FOUND", message);
  }
}

export class ConflictError extends AppError {
  constructor(message: string) {
    super(409, "CONFLICT", message);
  }
}

/** Untick refused because the row already has a decision maker in column M. */
export class DecisionMakerPresentError extends AppError {
  constructor(message: string) {
    super(409, "HAS_DECISION_MAKER", message);
  }
}

/** The workbook stayed locked/throttled after server-side retries. The client retries and says so. */
export class SheetBusyError extends AppError {
  constructor(message = "Sheet busy, retrying…") {
    super(503, "SHEET_BUSY", message);
  }
}

/** A write outside the allowed tab/columns/rows was attempted. This is a bug, never user error. */
export class ForbiddenWriteError extends AppError {
  constructor(message: string) {
    super(403, "FORBIDDEN_WRITE", message);
  }
}

export class UpstreamError extends AppError {
  constructor(message: string) {
    super(502, "UPSTREAM", message);
  }
}
