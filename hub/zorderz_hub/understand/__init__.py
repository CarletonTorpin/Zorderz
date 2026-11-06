"""Charts' deterministic question reader.

    terms   = terms.build(pack)                      # once per pack
    reading = read.read(question, Context(terms, anchor=data_as_of, today=today), previous=spec2)

Modules:
  lexicon  Charts' own words (measures, splits, time, comparisons, rankings, chart looks)
  terms    the phrase book: lexicon + the pack's products, towns, people, brands, sources
  text     normalising and typo-fixing toward known words only
  when     dates in words → period tokens
  periods  period tokens → dated windows (the only date arithmetic in Charts)
  read     a question → a sparse "version 2" spec, with what was and wasn't understood
  execute  running a v2 spec with compute.run (comparisons, date parts, averages, rankings)
  describe titles and the "How I read this" line, built from what ran
  charttype the chart for the answer's shape
  caption  facts → template caption; checks for a model caption
"""
