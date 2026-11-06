"""Every test runs in a scratch folder: no test reads or writes a real key folder, store or log.

Imported first by every test module (the hub reads these variables when it is imported).
"""
import atexit
import os
import shutil
import sys
import tempfile
from pathlib import Path

BASE = Path(tempfile.mkdtemp(prefix="zorderz-hub-tests-"))
atexit.register(shutil.rmtree, BASE, ignore_errors=True)
os.environ["ZORDERZ_HUB_CONFIG_DIR"] = str(BASE / "config")
os.environ["ZORDERZ_HUB_HOME"] = str(BASE / "home")
os.environ.pop("ZORDERZ_HUB_SECRET", None)
os.environ.pop("ZORDERZ_HUB_ANALYTICS_KEY", None)
os.environ["ZORDERZ_HUB_ACCOUNTS_FILE"] = str(BASE / "config" / "accounts.yml")
HUB = Path(__file__).resolve().parents[1]
if str(HUB) not in sys.path:
    sys.path.insert(0, str(HUB))

# invented invoice lines (fictional people, 555 numbers, example.com addresses, fictional ZIP codes)
INVOICES_CSV = """Invoice Number,Date Issued,Status,Client Name,Organization,Email,Phone,Address Line 1,City,Zip Code,Salesperson,Lead Source,Item,Quantity,Rate,Line Total
1001,2024-03-04,paid,Alex Example,,alex@example.com,555-0101,100 MAIN ST,Northtown,00011,AM,Google Ads,Solar window film install Brightline,4,150,600.00
1001,2024-03-04,paid,Alex Example,,alex@example.com,555-0101,100 MAIN ST,Northtown,00011,AM,Google Ads,Deposit,1,-100,-100.00
1002,2024-05-10,paid,Jordan Sample,,jordan@example.com,555-0102,110 OAK AVE,Southport,00012,JB,friend,Shade sail install,2,400,800.00
1003,2024-07-22,sent,Example Rentals LLC,Example Rentals LLC,office@example.com,555-0103,120 PINE ST,Midvale,00015,SR,website,Gutter guards 60 ft,60,8,480.00
1004,2025-02-02,paid,Alex Example,,alex@example.com,555-0101,100 MAIN ST,Northtown,00011,AM,,Privacy window film repair Northstar,1,120,120.00
1005,2025-04-18,paid,Sam Placeholder,,sam@example.com,555-0104,130 MAPLE DR,Westbrook,00014,JB,google ads,Chimney cap install,2,250,500.00
1006,2025-06-30,paid,Riley Placeholder,,riley@example.com,555-0105,140 CEDAR LN,Eastvale,00013,SR,repeat,Patio lights install,10,45,450.00
1007,2025-08-11,void,Casey Placeholder,,casey@example.com,555-0106,150 ELM ST,Midvale,00015,AM,,Solar window film install,3,150,450.00
"""
