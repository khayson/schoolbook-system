# Importing customers and what they owe (paper ledger)

For moving the shop's existing schools and their debts into the system once, before go-live.

## 1. Fill the template

Copy `docs/templates/customers-import-template.csv`, open it in Excel or Google Sheets, delete the example row and add one row per customer. Save as **CSV**.

| Column | Required | Notes |
|---|---|---|
| `name` | yes | As the school is known. |
| `region` | yes | Exactly a region name, e.g. `Central`, `Greater Accra`. |
| `type` | no | `school` (default), `reseller` or `individual`. |
| `district`, `address`, `contact_person`, `email`, `notes` | no | |
| `phone` | no, but use it | With the name, it is how the import recognises a customer already in the system. |
| `credit_limit` | no | GHS, e.g. `5000.00`. Empty = no limit. |
| `opening_balance` | no | What the school owes from before the system, in GHS (`2,500.00` or `2500.00`, at most 2 decimals). Empty = owes nothing. |
| `opening_balance_date` | no | When the debt arose, `YYYY-MM-DD`. Empty = the day of the import. |
| `opening_balance_due` | with a balance | When it is due, `YYYY-MM-DD`. Used for overdue and aging. |
| `code` | no | Only to re-run a file for customers already imported (`CUS-0007`). |

Dates must be written `YYYY-MM-DD` (Excel may change them: format the column as text).

## 2. Check it (nothing is written)

```
php artisan customers:import path\to\customers.csv
```

The command lists every row as **create**, **skip** (already a customer: same code, or same name and phone; or the same school twice in the file) or **error** (and why), then:

```
To create: 3   Skipped: 2   Errors: 2
Total opening balances to create: GHS 3,700.00
```

**Compare that total with the paper ledger's total.** Fix the rows with errors and run again until the total matches and there are no errors.

## 3. Import

```
php artisan customers:import path\to\customers.csv --commit
```

All rows are created in one go, or none (a file with any error is refused). Each opening balance becomes an invoice numbered `OB-...` on the customer, shown on statements as "Balance brought forward". Running the same file again creates nothing new.

## 4. After

- Check a few customers in the admin: what they owe, and their statement.
- `php artisan customers:reconcile` must say "All money invariants hold."
- A wrong opening balance: void its `OB-` invoice (with a reason) on the sale's page, then use **Opening balance** on the customer's page to enter the right one. One live opening balance per customer.
