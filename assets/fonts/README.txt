Poppins font files
==================

The interface uses Poppins. The stylesheet looks for it in two places, in order:

  1. A copy installed on the viewer's computer  (src: local("Poppins ..."))
  2. These files, served from this folder        (src: url("poppins-NNN.woff2"))

If neither is available it falls back to the system font and the interface
still reads correctly - only the typeface differs.

For an intranet server with no internet access, put these four files here so
every client gets Poppins regardless of what is installed locally:

    poppins-400.woff2    (Regular)
    poppins-500.woff2    (Medium)
    poppins-600.woff2    (SemiBold)
    poppins-700.woff2    (Bold)

Poppins is licensed under the SIL Open Font License 1.1, which permits
redistribution with the application. Get the files from:

    https://fonts.google.com/specimen/Poppins

No other change is needed - the stylesheet already points at these names.
