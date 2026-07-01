# FatturaPA XSD

To enable `XmlBuilder::validate()`, place the official Agenzia delle Entrate schema here as:

```
FatturaPA_v1.2.2.xsd
```

Download it from the Fatturazione Elettronica documentation on
<https://www.fatturapa.gov.it>. It is **not vendored** to avoid redistributing a
government-published file of uncertain license. Without it, `build()` still works;
`validate()` returns a single informational message instead of validating.
