import type { SessionRestriction } from '../../../types/auth';
import { ForcePasswordChangePage } from './ForcePasswordChangePage';
import { MfaEnrollmentPage } from './MfaEnrollmentPage';

/** Paso de seguridad pendiente: primero la contraseña, después el MFA. */
export const SecurityGate = ({ restriction }: { restriction: SessionRestriction }) =>
  restriction === 'PASSWORD_CHANGE_REQUIRED' ? <ForcePasswordChangePage /> : <MfaEnrollmentPage />;
