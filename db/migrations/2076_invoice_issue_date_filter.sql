-- Integrační výpis podle data vystavení bez omezení stavu dokladu.
CREATE INDEX IF NOT EXISTS idx_inv_supplier_issue ON invoices (supplier_id, issue_date);
