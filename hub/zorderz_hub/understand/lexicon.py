"""The question reader's own words: what every business's Charts questions share.

A business's words (products, towns, people, brands, lead sources) come from the Identity Pack
(terms.yml and the other pack files); nothing here names a business's facts.

Every table maps a phrase (lower case, words separated by single spaces) to what it sets.
The reader always takes the LONGEST phrase that matches at a position, so "sales person"
beats "sales", and "same point last year" beats "last year".
"""

# ---------------------------------------------------------------- measures
MEASURE_WORDS = {
    "revenue": ["revenue", "revenues", "revnue", "sales", "sale", "dollars", "dollar", "money", "income",
                "billed", "bill", "billing", "made", "make", "earn", "earned", "earnings", "brought in",
                "brings in", "bring in", "gross", "how much", "turnover", "takings", "$"],
    "jobs": ["jobs", "job", "invoices", "invoice", "orders", "order", "installs done", "how many times",
             "times", "number of jobs", "job count", "count", "visits", "projects", "how many jobs"],
    "customers": ["customers", "customer", "clients", "client", "households", "household",
                  "how many customers", "number of customers"],
    "units": ["units", "unit", "pieces", "piece", 
              "units installed", "panels", "how many units",
              "total units", "number of units"],
    "avg_job": ["average job", "average jobs", "avg job", "average ticket", "avg ticket", "average sale",
                "average sales", "average job size", "job size", "typical job", "typical job size",
                "average revenue per job", "revenue per job", "average invoice", "average order",
                "biggest average job", "average job value", "per job"],
}

# "how many <product>" means jobs unless a units word is present.
HOW_MANY = ["how many", "number of", "count of", "how often"]

# ---------------------------------------------------------------- splits (by what)
DIMENSION_WORDS = {
    "product": ["product", "products", "product line", "product lines", "product type", "product types",
                "item", "items", "line of business", "lines of business", "what we sell", "category",
                "categories", "product category", "product categories"],
    "area": ["town", "towns", "city", "cities", "area", "areas", "neighborhood", "neighborhoods",
             "neighbourhood", "neighbourhoods", "neighbor hood", "location", "locations", "place", "places",
             "community", "communities", "region", "regions", "neighbor"],
    "salesperson": ["salesperson", "salespeople", "sales person", "sales persons", "sales people",
                    "salesman", "salesmen", "rep", "reps", "sales rep", "sales reps", "seller", "sellers",
                    "staff", "team member", "team members", "who sold", "who sells", "employee", "employees",
                    # staff, not customers ("who are our top" alone is the customer-names guard)
                    "who are our top sellers", "who are our best sellers", "who are our top salespeople",
                    "who are our best salespeople", "who are our top sales people", "who are our top reps"],
    "lead_source": ["lead source", "lead sources", "source", "sources", "channel", "channels",
                    "marketing source", "marketing sources", "marketing channel", "marketing channels",
                    "referral type", "referral types", "referral source", "referral sources", "how they found us",
                    "where they came from", "where our jobs came from", "where did our jobs come from",
                    "where jobs come from", "where leads come from", "lead type", "lead types"],
    "referral_partner": ["referral partner", "referral partners", "partner", "partners", "partner company",
                         "partner companies", "company that referred us", "companies that referred us",
                         "company who referred us", "companies who referred us", "company that referred",
                         "who referred us", "who refers us", "who refer us", "who have referred us",
                         "who has referred us", "that refer us", "that referred us", "referring company",
                         "referring companies", "referrer", "referrers", "based on company that referred us"],
    "work_type": ["work type", "work types", "type of work", "types of work", "job type", "job types",
                  "sales category", "sales categories", "service / new install", "service vs new install",
                  "service and new install", "service or new install", "new install vs service",
                  "new vs service", "install vs repair", "repair vs install", "service type"],
    "original_source": ["original source", "first source", "how the customer first found us",
                        "how they first found us", "first found us"],
    "account": ["account", "accounts", "named account", "named accounts"],
}
# Detail groups people name directly (tag_<key> splits): used only for a key the pack's catalog has;
# a tag group's own `words` in the catalog add to these.
DETAIL_SPLIT_WORDS = {
    "brand": ["brand", "brands", "manufacturer", "manufacturers", "model", "models"],
    "service_style": ["service style", "service styles", "style of service"],
    "purpose": ["purpose", "purposes"],
}
# "types"/"styles"/"kinds" of a named product: the pack's product_detail_split decides.
TYPE_WORDS = ["type", "types", "style", "styles", "kind", "kinds", "variety", "varieties", "category",
              "categories", "all styles", "all types"]

# Words that introduce a split ("by town", "per salesperson", "for each product").
SPLIT_INTRO = ["by", "per", "for each", "each", "split by", "split it by", "split them by", "broken down by",
               "broken out by", "break down by", "break it down by", "breakdown by", "grouped by", "group by",
               "across", "within a column per", "a column per", "one column per", "a line per", "one line per",
               "a bar per", "one bar per", "in each", "for every", "every"]

# ---------------------------------------------------------------- time grains and date parts
GRAIN_WORDS = {
    "year": ["by year", "per year", "each year", "every year", "yearly", "annually", "annual", "year by year",
             "year over year by year", "by the year", "years", "over the years", "year on year", "a year",
             "year-by-year"],
    "quarter": ["by quarter", "per quarter", "each quarter", "every quarter", "quarterly", "quarter by quarter",
                "by quarters"],
    "month": ["by month", "per month", "each month", "every month", "monthly", "month by month", "months",
              "by the month", "month over month", "month-by-month"],
    "week": ["by week", "per week", "each week", "every week", "weekly", "week by week", "weeks",
             "week-by-week"],
    "day": ["by day", "per day", "each day", "every day", "daily", "day by day"],
}
DATE_PART_WORDS = {
    "day_of_week": ["day of the week", "day of week", "days of the week", "weekday", "weekdays",
                    "by day of the week", "by day of week", "by weekday",       # not "by day" + leftovers
                    "which day", "what day", "day of the week do we"],
    "season": ["season", "seasons", "seasonal", "seasonality", "by season", "which season", "what season",
               "each season", "time of year"],
    "month_of_year": ["month of the year", "month of year", "months of the year", "which month of the year",
                      "which month", "which months", "what month", "what months",
                      "by month of the year", "by month of year",
                      "typical month", "calendar month", "by calendar month"],
    "quarter_of_year": ["quarter of the year", "which quarter of the year", "calendar quarter"],
    "week_of_year": ["week of the year", "week of year", "weeks of the year"],
}
TREND_WORDS = ["trend", "trends", "over time", "trajectory", "history", "historically", "growth", "grew",
               "grow", "growing", "change over time", "how has", "how have", "timeline"]

# ---------------------------------------------------------------- comparisons
COMPARE_SPLITTERS = ["vs", "vs.", "versus", "compared to", "compared with", "comparing", "compare to",
                     "compare with", "against", "relative to", "next to", "as opposed to"]
COMPARE_LEAD = ["compare", "comparing", "comparison of", "comparison between", "difference between"]
PRIOR_YEAR = ["same point last year", "same time last year", "same period last year", "same point in time last year",
              "same date last year", "same dates last year", "same days last year", "the same point last year",
              "vs last year", "versus last year", "compared to last year", "compared with last year",
              "against last year", "than last year", "over last year", "from last year", "year over year",
              "year-over-year", "yoy", "y/y", "prior year", "previous year same period",
              "last year's same period", "the year before"]
PRIOR_PERIOD = ["prior period", "previous period", "period over period", "month over month",
                "quarter over quarter", "week over week", "the month before", "the quarter before",
                "the week before", "previous month", "previous quarter", "previous week", "prior month",
                "prior quarter", "mom", "qoq"]
TO_DATE_WORDS = ["to date", "so far", "year to date", "ytd", "normalize", "normalise", "normalized",
                 "normalised", "same window", "same date range", "same stretch", "same part of the year",
                 "like for like", "like-for-like", "apples to apples", "zone"]

# ---------------------------------------------------------------- ranking
RANK_TOP = ["top", "best", "most", "biggest", "largest", "highest", "busiest", "strongest", "leading",
            "greatest", "record", "peak", "max", "maximum", "grew the most", "grown the most", "most popular",
            "best selling", "best-selling", "sell most", "sells the most", "sold the most"]
RANK_BOTTOM = ["worst", "least", "lowest", "smallest", "slowest", "weakest", "fewest", "bottom", "minimum",
               "min", "quietest",
               # "slow" months are the bottom of the year ("slowest month" no longer eats "month", so
               # "slowest month ever" keeps the month grain like "best month ever"). read.py keeps "slow"/"quiet"
               # a ranking only beside a time word ("slow paying customers" is not a ranking)
               "slow", "quiet"]

# ---------------------------------------------------------------- chart looks
CHART_WORDS = {
    "stacked_bar": ["stacked", "stack", "stacked bar", "stacked bars", "section on the bar", "sections on the bar",
                    "a section on the bar", "each neighbor is a section", "segments", "segmented"],
    "grouped_bar": ["side by side", "side-by-side", "grouped", "next to each other", "clustered",
                    "within the same column", "in the same column"],
    "percent_bar": ["100%", "percent stacked", "share of each bar", "percentage of total in each bar",
                    "100 percent", "split to represent their share", "represent their share"],
    "line": ["line", "line chart", "line graph", "trend line", "lines"],
    "area": ["area chart", "filled", "area graph"],
    "pie": ["pie", "pie chart"],
    "donut": ["donut", "doughnut", "ring"],
    "waffle": ["waffle", "brick wall", "grid of squares", "unit chart"],
    "table": ["table", "list", "listing", "spreadsheet"],
    "single_number": ["just the number", "one number", "single number", "the number only", "a number"],
    # towns on a map. Phrases only: a bare "map" / "maps" is often a lead source ("Google Maps").
    "map": ["on a map", "on the map", "as a map", "in a map", "map view", "map it", "show a map", "draw a map",
            "make a map", "map of"],
}
ORIENTATION_WORDS = {"horizontal": ["horizontal", "sideways", "bars going across"],
                     "vertical": ["vertical", "columns", "column"]}
LABEL_WORDS = {"value": ["numbers on", "amounts on", "show the numbers", "with numbers", "values on", "labels",
                         "label the bars"],
               "percent": ["percentages", "percentage", "percent", "% of total", "as a percent"],
               }
SHARE_WORDS = ["share", "shares", "percent", "percentage", "percentages", "%", "proportion", "proportions",
               "portion", "mix", "breakdown of", "what part of", "what fraction", "fraction", "split of",
               "came from each", "split to represent their share", "ratio", "ratios"]

# ---------------------------------------------------------------- averages
AVG_PER_PERIOD = ["average monthly", "monthly average", "average per month", "avg per month", "average month",
                  "per month on average", "average weekly", "weekly average", "average per week",
                  "average yearly", "yearly average", "average per year", "average annual",
                  "average quarterly", "quarterly average", "average per quarter", "on average"]
UNITS_PER_JOB = ["per each job", "per job", "units per job", "per installation", "units per each job",
                 "number of units per job"]

# ---------------------------------------------------------------- customers
REPEAT_WORDS = ["repeat customers", "repeat customer", "returning customers", "returning customer",
                "came back", "come back", "comes back", "repeat business", "customers who came back",
                "customers with 2 or more jobs", "repeat clients", "return customers"]
NEW_CUSTOMER_WORDS = ["new customers", "new customer", "first-time customers", "first time customers",
                      "non-repeat", "non repeat", "not existing customers", "new clients"]
JOBS_PER_CUSTOMER = ["how many jobs does a customer", "jobs per customer", "jobs does a customer usually",
                     "how often do customers come back", "how many times do customers"]

# ---------------------------------------------------------------- what Charts can't answer (honest refusals)
UNSUPPORTED = [
    # (phrases, reason, fallback)
    (["profit", "profits", "margin", "margins", "profit margin", "cost", "costs", "expense", "expenses",
      "overhead", "markup", "net income", "roi", "return on investment", "ad spend", "cost per lead",
      "labor cost", "labour cost", "hours worked", "payroll", "wages", "commission", "commissions"],
     "Charts has no cost data, so profit and margins aren't available.", "revenue_by_product"),
    (["predict", "prediction", "predictions", "forecast", "forecasts", "forecasting", "projection",
      "projections", "project next", "next month's", "next year's", "next quarter's", "will we make",
      "going to make", "estimate next"],
     "Charts shows what happened; it doesn't forecast.", "recent_history"),
    (["estimates did we send", "estimates sent", "how many estimates", "estimate count", "number of estimates",
      "quotes did we send", "how many quotes", "close rate", "conversion rate", "win rate"],
     "Charts holds only a few estimates so far, so estimate counts would be wrong.", "none"),
    (["owe us", "owes us", "who owes", "outstanding balance", "outstanding balances", "unpaid invoices by customer",
      "accounts receivable by customer", "overdue customers", "past due customers"],
     "Charts doesn't show individual customers. The money-owed view in the app lists unpaid invoices.", "none"),
    (["cash collected", "collections", "cash flow", "deposits", "bank"],
     "Charts counts what was billed, not cash collected.", "revenue_by_month"),
    (["inventory", "stock level", "stock levels", "in stock", "warehouse"],
     "Charts has no inventory data.", "revenue_by_product"),
    (["realtor", "realtors", "real estate", "real estate agent", "real estate agents", "realty", "realtor referrals"],
     "No lead-source group names realtors, so Charts can't count realtor referrals; any typed in are counted "
     "under the group their words fall in.", "jobs_by_source"),
]

# What each word reads as (weatherview.VARS). "Cold" is a cool day, read as the daily high; only
# "lows"/"overnight" mean the low.
WEATHER_WORDS = {
    "tmax": ["temperature", "temperatures", "temp", "temps", "heat", "hot", "hotter", "hottest", "warm", "warmer",
             "warmest", "weather", "degrees", "daily high", "daily highs", "high temperature", "high temperatures",
             "the highs", "heat wave", "heat waves", "heatwave", "heatwaves", "hot weather", "warm weather",
             "cold", "colder", "coldest", "cold weather", "cool weather", "cooler weather", "chilly", "sunny"],
    "tmin": ["low temperature", "low temperatures", "overnight low", "overnight lows", "overnight", "nightly low",
             "nightly lows", "night temperatures", "nighttime temperatures", "cold nights", "the lows"],
    "tavg": ["average temperature", "average temperatures", "mean temperature", "mean temperatures",
             "avg temperature", "avg temp", "average temp"],
    "prcp": ["rain", "rains", "rained", "raining", "rainfall", "rainy", "precipitation", "wet", "wetter", "wettest",
             "stormy", "rainstorm", "rainstorms", "showers", "drizzle", "dry", "drier", "driest", "drought",
             "inches of rain"],
    "rain_days": ["rainy days", "days of rain", "days it rained", "days with rain", "rain days", "wet days"],
    "hot_days": ["hot days", "days over 90", "days above 90", "90 degree days", "90-degree days", "scorching days",
                 "very hot days"],
}
WEATHER_VIEW_WORDS = ["dots", "dot", "scatter", "scatterplot", "scatter plot", "scatter chart", "as points",
                      "second axis", "overlay"]
# "on hot days", "when it rains": day by day, grouped by the day's weather
WEATHER_BANDS = r"(?:\bwhen (?:it(?:'s|’s| is| was)?|it gets|the weather is) (?:\w+ )?(?:hot|cold|warm|cool|rain\w*|wet|dry|" \
                r"sunny|stormy)|\bon (?:a |the )?(?:hot|cold|warm|cool|rainy|wet|dry|sunny|stormy)(?: \w+)? days?\b|" \
                r"\b(?:hot|cold|warm|rainy|wet|dry) days? (?:vs|versus|or|compared)\b|\bby (?:the )?(?:temperature|" \
                r"rain\w*|weather)\b|\bduring (?:a |the )?(?:heat wave|heatwave|rain|storm)s?\b|\bin the (?:heat|rain)\b|" \
                r"\bon days (?:over|above|below|under|with)\b)"
# "how does the heat affect sales", "revenue vs temperature": the link, shown first as dots
WEATHER_SCATTER = r"\b(?:correlat\w*|relationship|related|relate|link(?:ed)?|affects?|affected|affecting|impacts?|" \
                  r"impacted|effects?|influenc\w*|depends?|depend on|drives?|driven|scatter|dots?)\b"
# "the week after it rains", "2 weeks later", "a month before": the weather earlier than the sales
WEATHER_LAG = r"\b(?:(?P<n>\d+|a|one|two|three|four)\s+)?(?:the\s+)?(?P<unit>day|days|week|weeks|month|months)\s+" \
              r"(?:after|following|later)\b"
WEATHER_WEAK = {"hot", "hotter", "hottest", "warm", "warmer", "warmest", "cold", "colder", "coldest", "dry", "drier",
                "driest", "wet", "wetter", "wettest", "heat", "temp", "temps", "overnight", "sunny", "chilly",
                "degrees", "stormy", "showers", "drizzle"}
WEATHER_WEAK_NEXT = {"weather", "day", "days", "month", "months", "week", "weeks", "spell", "spells", "summer",
                     "summers", "winter", "winters", "night", "nights", "afternoon", "afternoons", "season",
                     "seasons", "wave", "waves", "temperature", "temperatures", "out", "outside"}
WEATHER_WEAK_PREV = {"it's", "it’s", "its", "is", "was", "gets", "got", "getting", "when", "the", "in"}
# "with rain the week before", "2 days after it rains": the weather earlier than the sales (read before dates)
WEATHER_LAG_ANY = r"\b(?:the\s+)?(?:(?P<n>\d+|a|one|two|three|four)\s+)?(?P<unit>days?|weeks?|months?)\s+" \
                  r"(?:before|earlier|prior|after|following|later)\b"
# a question with these words (and no other sales word) is still about sales: "is it busier when it's hot?"
SALES_CONTEXT = ["busy", "busier", "busiest", "slow", "slower", "slowest", "business", "demand", "sell", "sold",
                 "selling", "sales", "installs", "booked", "bookings", "calls"]

# What each phrase reads as (zipfacts.FACTS). "Income" alone stays revenue, "households" alone stays customers:
# only the longer phrases are facts.
ZIP_FACT_WORDS = {
    "income": ["median income", "median incomes", "median household income", "household income",
               "household incomes", "income level", "income levels", "incomes", "income bracket",
               "income brackets", "wealth", "wealthy", "wealthier", "wealthiest", "affluent", "affluence",
               "affluent areas", "richer", "richest", "rich neighborhoods", "rich areas"],
    "home_value": ["home value", "home values", "house value", "house values", "median home value",
                   "median home values", "home price", "home prices", "house price", "house prices",
                   "housing prices", "property value", "property values", "expensive homes",
                   "expensive neighborhoods", "pricey neighborhoods"],
    "population": ["population", "populations", "residents", "populous", "number of people"],
    "density": ["density", "population density", "dense", "denser", "densest", "people per square mile",
                "crowded", "urban", "rural"],
    "households": ["number of households", "household count", "how many households", "count of households"],
    "homes": ["number of homes", "housing units", "how many homes", "home count", "count of homes",
              "number of houses", "housing stock"],
    "owner_pct": ["homeowners", "homeowner", "home owners", "homeownership", "home ownership", "owner occupied",
                  "owner-occupied", "renters", "renter", "renting", "rentals", "rental homes",
                  "owners vs renters", "own their home", "own their homes"],
    "single_family_pct": ["single family", "single-family", "single family homes", "single-family homes",
                          "detached homes", "detached houses", "condos", "apartments", "townhomes"],
    "year_built": ["year built", "median year built", "age of homes", "age of the homes", "home age",
                   "house age", "housing age", "age of houses"],
    "pre1980_pct": ["older homes", "old homes", "older houses", "old houses", "homes built before 1980",
                    "built before 1980", "pre 1980", "pre-1980", "older neighborhoods", "older housing"],
    "built_1980s_90s_pct": ["built in the 80s", "built in the 1980s", "built in the 90s", "built in the 1990s",
                            "80s and 90s homes", "homes from the 80s", "homes from the 90s"],
    "built_2000_pct": ["newer homes", "newer houses", "built after 2000", "built since 2000", "newer neighborhoods"],
    "age": ["median age", "age of residents", "older residents", "younger residents", "retirees", "retirement",
            "older people", "younger people", "older population"],
    "land_sqmi": ["land area", "square miles", "size of the zip", "size of each zip"],
    "fire_pct": ["fire zone", "fire zones", "fire hazard", "fire hazards", "fire hazard zone", "fire hazard zones",
                 "fire risk", "wildfire", "wildfires", "wildfire risk", "wildfire zone", "wildfire zones",
                 "high fire", "fire severity", "fire hazard severity", "brush fire", "brush fires",
                 "fire country", "fire prone", "fire-prone"],
    "fire_vh_pct": ["very high fire", "very high fire zone", "very high fire zones", "very high fire hazard",
                    "very high fire risk", "very high hazard"],
    # 65 and older, and the place facts (places.py)
    "age65_pct": ["65 and older", "65 or older", "over 65", "aged 65", "age 65", "65 plus", "seniors",
                  "senior citizens", "senior population", "elderly", "share of seniors"],
    "coast_mi": ["distance to the coast", "distance from the coast", "distance to the ocean", "distance from the ocean",
                 "miles to the coast", "miles from the coast", "miles to the ocean", "miles from the ocean",
                 "close to the coast", "close to the ocean", "near the coast", "near the ocean", "salt air",
                 "how far from the coast", "how far from the ocean"],
    "burned_pct": ["burned before", "burned areas", "burn area", "burn areas", "burn scar", "burn scars",
                   "fire history", "wildfire history", "homes that burned", "past fires", "past wildfires"],
    "fire_count": ["number of fires", "how many fires", "fires since 1950", "wildfires since 1950", "fire count",
                   "historic fires", "fires on record"],
    "last_fire_year": ["last fire", "last wildfire", "most recent fire", "most recent wildfire",
                       "when it last burned", "years since the last fire"],
    "vector_q8": ["mosquito", "mosquitoes", "mosquitos", "aedes", "aedes aegypti", "yellow fever mosquito",
                 "yellow fever mosquitoes", "invasive mosquitoes", "mosquito season", "vector control"],
    "tax_rate": ["sales tax rate", "sales tax rates", "tax rate", "tax rates", "sales tax"],
}
# a profile: the facts of the ZIPs or towns named
ZIP_PROFILE_WORDS = ["demographics", "demographic", "demographic info", "demographic information",
                     "census data", "census info", "census information", "census", "profile", "zip profile",
                     "tell me about", "what is it like", "what's it like", "whats it like", "what are they like",
                     "facts about", "info about", "info on", "information about", "information on",
                     "who lives", "who lives there", "neighborhood profile"]
ZIP_PROFILE_LIKE = r"\bwhat(?:s| is| are)?\s+(?:\w+\s+){1,3}like\b"     # "what's Cedar Hills like" (after prepare)
# everyday words that are a ZIP fact only on a ZIP question (a ZIP, map, shading, dots or rate
# word nearby, or "by <fact>"). Elsewhere they keep their old meaning: "jobs at older homes" is residential jobs,
# "info on hot leads" is about leads, "what is revenue like this year" is revenue.
# every place word, and the 65+ words, are weak too ("patio light", "sales tax collected", "seniors'
# discount" are about sales; only a ZIP question, or "by <fact>", makes them a ZIP fact)
ZIP_FACT_WEAK_0220 = {w for k in ("age65_pct", "coast_mi", "burned_pct", "fire_count", "last_fire_year", "vector_q8",
                                  "tax_rate") for w in ZIP_FACT_WORDS[k]}
ZIP_FACT_WEAK = ZIP_FACT_WEAK_0220 | {"wealth", "wealthy", "wealthier", "wealthiest", "affluent", "affluence", "affluent areas", "richer",
                 "richest", "rich neighborhoods", "rich areas", "expensive homes", "expensive neighborhoods",
                 "pricey neighborhoods", "residents", "populous", "number of people", "dense", "denser", "densest",
                 "crowded", "urban", "rural", "number of homes", "how many homes", "home count", "count of homes",
                 "number of houses", "homeowners", "homeowner", "home owners", "renters", "renter", "renting",
                 "rentals", "rental homes", "own their home", "own their homes", "single family", "single-family",
                 "single family homes", "single-family homes", "detached homes", "detached houses", "condos",
                 "apartments", "townhomes", "older homes", "old homes", "older houses", "old houses",
                 "older neighborhoods", "older housing", "newer homes", "newer houses", "newer neighborhoods",
                 "older residents", "younger residents", "retirees", "retirement", "older people", "younger people",
                 "older population", "fire risk", "wildfire", "wildfires", "high fire", "fire severity", "brush fire",
                 "brush fires", "fire country", "fire prone", "fire-prone", "square miles", "size of the zip",
                 "size of each zip", "number of households", "household count", "how many households",
                 "count of households"}
ZIP_PROFILE_WEAK = {"profile", "tell me about", "what is it like", "what's it like", "whats it like",
                    "what are they like", "facts about", "info about", "info on", "information about",
                    "information on"}
# a link word that can only be about the fact ("vs", "against" usually mean another year: not context)
ZIP_LINK = r"\b(?:correlat\w*|relationship|related|relate|link(?:ed)?|affects?|affected|affecting|impacts?|effects?|" \
           r"influenc\w*|depends?|depend on|drives?|driven|scatter|dots?|track(?:s|ed)? with|go(?:es)? with)\b"
ZIP_CONTEXT = r"\b(?:zips?|zip codes?|zipcodes?|postal codes?|maps?|shade[ds]?|shading|colou?r(?:ed|s)?|heat ?map|" \
              r"census|demographics?|neighbou?rhoods?|per (?:1 000|1000|thousand|capita|household|home|person))\b"
# "per 1,000 homes", "per household", "per capita": the chart's numbers over each ZIP's size
ZIP_RATE = r"\b(?:per|for every|every)\s+(?:(?:1[ ,]?000|one thousand|a thousand|thousand|1k|100|hundred|10[ ,]?000)\s+)?" \
           r"(?P<base>homes?|houses?|housing units?|households?|people|persons?|residents?|capita|head|population)\b"
ZIP_PENETRATION = r"\b(?:penetration|market share by zip|share of (?:the )?(?:homes|households))\b"
ZIP_SHADE = r"\b(?:shade[ds]?|shading|colou?r(?:ed|s)?|tint(?:ed)?|heat ?map)\b"
ZIP_DOTS = r"\b(?:vs\.?|versus|against|compared (?:to|with)|correlat\w*|relationship|related|relate|link(?:ed)?|" \
           r"affects?|affected|affecting|impacts?|effects?|influenc\w*|depends?|depend on|drives?|driven|scatter|" \
           r"dots?|track(?:s|ed)? with|go(?:es)? with)\b"

# Ordinary English words that are never "corrected" toward a product or town ("ratio" is not
# "patio", "lead" is not "led"). Left unread, they make the reading partial: honest.
COMMON_WORDS = set("""
safe council councils wise fire fires sites site neighborhoods neighbourhoods
maps mapping ratio ratios rate rates state states store stores price prices order orders lead leads color colour colors
weather whether rather other either never ever later water after under value values cause because house houses
home homes door work worker workers team teams truck trucks phone calls call online office offices school
schools church holiday holidays sunny rainy cold hot warm heat busy slow fast best worst most least high low
peak trend trends growth drop rise fall spike dip normal median mean typical usual quite quality review
reviews rating ratings margin profit cost tell list name names many much more less size sizes type types
kind kinds style styles color model models brand brands part parts piece pieces item items line lines
price paid unpaid owed bill billed billing check checks cash card cards visa mail email emails text texts
word words page pages site sites town city cities county region area areas zone street road
""".split())
ZIP_WORDS = ["zip", "zip code", "zip codes", "zipcode", "zipcodes", "postal code", "postal codes", "postcode"]
CUSTOMER_NAME_WORDS = ["customer names", "customer name", "names of customers", "names of our customers",
                       "every customer", "list of customers", "list customers", "list every customer",
                       "customer list", "top customers", "biggest customers", "best customers",
                       "customer's address", "customer addresses", "phone numbers", "emails of",
                       "who are our top", "which customers"]
INJECTION = [r"\bignore (?:all |any |your |the |previous |prior |above )*(?:instructions|rules|prompt)",
             r"\bsystem prompt\b", r"\bdeveloper mode\b", r"\bjailbreak\b", r"\bpretend (?:you are|to be)\b",
             r"\breveal (?:your|the) (?:prompt|instructions)\b", r"\bdisregard (?:all |your |previous )*(?:instructions|rules)"]

# ---------------------------------------------------------------- follow-ups
FOLLOWUP_WORDS = ["now", "instead", "same", "same but", "same chart", "same thing", "also", "just", "only",
                  "make it", "change it", "split it", "break it", "show it", "as a", "what about", "how about",
                  "and for", "but for", "this time", "that chart", "the chart", "it"]

# ---------------------------------------------------------------- filler
# Words that carry no meaning for the spec once the rest is read. A question whose every
# other word was placed is "fully read" and needs no model.
STOPWORDS = set("""
a an the of in on at to for from with without about into onto over under than then and or but nor so yet as like
is are was were be been being am do does did doing done have has had having will would can could should may
might must shall i me my we us our ours you your yours they them their it its this that these those there here
what which who whom whose when where why how much many show shows showing me give get got see display chart
charts graph graphs plot draw view look tell let please can could would like want need know find
total totals all any some each every overall whole entire complete altogether combined together just only
also too very really much more less number numbers amount amounts figure figures stats statistics data
did do does sold sell sells selling sale sales performance perform performed done doing go went
up down out off back again ever still yet even both either neither other others another else
our we've we're weve were ours ourselves company business
one two three four five six seven eight nine ten eleven twelve
first second third fourth last next previous past recent recently current currently this
year years month months week weeks day days quarter quarters time times period periods
since until till through thru between during within around near close
up to now today date dates so far yet
something cool interesting anything nice fun neat
ones house houses
vs versus compare compared comparing comparison against
by per split broken break breakdown down grouped group across
what's whats how's hows where's who's isn't aren't wasn't don't doesn't didn't can't won't
i'd i'm i've id im ive lets let's
""".split())
