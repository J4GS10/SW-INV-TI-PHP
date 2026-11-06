import { createContext } from 'react';

/** True when a page is shown inside another one: the host already gives the title and the way back. */
export const EmbeddedPageContext = createContext(false);
