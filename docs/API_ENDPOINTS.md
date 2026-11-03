# API endpoint inventory

Generated from `server/public/api/index.php`: **218 routes**. Every route except the public ones requires a signed-in session (SEC-T01: 210 protected routes refused without one) and a CSRF token for writes. Public: `POST /auth/login`, `POST /auth/reset`, `GET/POST /public/forms/{token}`, `POST /cron/run` (secret token; disabled when unset), `GET /health`; `GET /auth/me` and `POST /auth/logout` respond without a session but reveal nothing. Permissions are enforced inside each controller.

## Auth (5)
| Method | Path | Handler |
|---|---|---|
| POST | `/auth/login` | AuthController::login |
| POST | `/auth/logout` | AuthController::logout |
| GET | `/auth/me` | AuthController::me |
| POST | `/auth/reset` | AuthController::resetPassword |
| POST | `/staff/{id}/reset-link` | AuthController::createResetLink |

## Booking (6)
| Method | Path | Handler |
|---|---|---|
| GET | `/bookings` | BookingController::list |
| POST | `/bookings` | BookingController::create |
| GET | `/bookings/{id}` | BookingController::get |
| PUT | `/bookings/{id}` | BookingController::update |
| PUT | `/bookings/{id}/travelers` | BookingController::setTravelers |
| POST | `/bookings/{id}/cancel` | BookingController::cancel |

## Call (20)
| Method | Path | Handler |
|---|---|---|
| GET | `/calls/capability` | CallController::capability |
| GET | `/calls/report` | CallController::report |
| GET | `/calls/export` | CallController::export |
| GET | `/calls` | CallController::list |
| POST | `/calls` | CallController::create |
| POST | `/calls/handoff` | CallController::handoff |
| GET | `/calls/{id}` | CallController::get |
| PUT | `/calls/{id}` | CallController::update |
| POST | `/calls/{id}/outcome` | CallController::outcome |
| POST | `/calls/{id}/archive` | CallController::archive |
| POST | `/calls/{id}/notes` | CallController::addNote |
| PUT | `/call-notes/{id}` | CallController::editNote |
| GET | `/call-dispositions` | CallController::dispositions |
| POST | `/call-dispositions` | CallController::saveDisposition |
| PUT | `/call-dispositions/{id}` | CallController::saveDisposition |
| GET | `/phone/lookup` | CallController::lookup |
| POST | `/phone/backfill` | CallController::backfillPhones |
| GET | `/contacts/{id}/phones` | CallController::contactPhones |
| POST | `/contacts/{id}/phones` | CallController::addPhone |
| DELETE | `/contacts/{id}/phones/{pid}` | CallController::deletePhone |

## Callback (5)
| Method | Path | Handler |
|---|---|---|
| GET | `/callbacks` | CallbackController::list |
| POST | `/callbacks` | CallbackController::create |
| POST | `/callbacks/{id}/reschedule` | CallbackController::reschedule |
| POST | `/callbacks/{id}/complete` | CallbackController::setStatus |
| POST | `/callbacks/{id}/cancel` | CallbackController::setStatus |

## Commission (13)
| Method | Path | Handler |
|---|---|---|
| GET | `/commission-rules` | CommissionController::rules |
| POST | `/commission-rules` | CommissionController::createRule |
| PUT | `/commission-rules/{id}` | CommissionController::updateRule |
| POST | `/bookings/{id}/commission` | CommissionController::calculate |
| GET | `/commissions` | CommissionController::list |
| GET | `/commissions/{id}` | CommissionController::get |
| POST | `/commissions/{id}/receipts` | CommissionController::receipt |
| POST | `/commissions/{id}/payouts` | CommissionController::payout |
| POST | `/commissions/{id}/adjustments` | CommissionController::adjust |
| POST | `/commissions/{id}/reverse` | CommissionController::reverse |
| POST | `/commissions/{id}/earn` | CommissionController::earn |
| POST | `/commission-receipts/{id}/reverse` | CommissionController::reverseLine |
| POST | `/advisor-payouts/{id}/reverse` | CommissionController::reverseLine |

## Contact (6)
| Method | Path | Handler |
|---|---|---|
| GET | `/contacts` | ContactController::list |
| POST | `/contacts` | ContactController::create |
| GET | `/contacts/staff-options` | ContactController::staffOptions |
| GET | `/contacts/{id}` | ContactController::get |
| PUT | `/contacts/{id}` | ContactController::update |
| DELETE | `/contacts/{id}` | ContactController::delete |

## Document (7)
| Method | Path | Handler |
|---|---|---|
| GET | `/documents` | DocumentController::list |
| POST | `/documents` | DocumentController::upload |
| GET | `/documents/{id}` | DocumentController::get |
| PUT | `/documents/{id}` | DocumentController::update |
| POST | `/documents/{id}/archive` | DocumentController::archive |
| GET | `/documents/{id}/download` | DocumentController::download |
| GET | `/portal/documents/{id}/download` | DocumentController::portalDownload |

## Email (7)
| Method | Path | Handler |
|---|---|---|
| GET | `/emails` | EmailController::list |
| POST | `/emails` | EmailController::create |
| GET | `/emails/{id}` | EmailController::get |
| PUT | `/emails/{id}` | EmailController::update |
| POST | `/emails/{id}/queue` | EmailController::queue |
| POST | `/emails/{id}/cancel` | EmailController::cancel |
| POST | `/emails/{id}/resolve` | EmailController::resolve |

## EmailTemplate (8)
| Method | Path | Handler |
|---|---|---|
| GET | `/email-templates/variables` | EmailTemplateController::variables |
| GET | `/email-templates` | EmailTemplateController::list |
| POST | `/email-templates` | EmailTemplateController::create |
| GET | `/email-templates/{id}` | EmailTemplateController::get |
| PUT | `/email-templates/{id}` | EmailTemplateController::update |
| POST | `/email-templates/{id}/duplicate` | EmailTemplateController::duplicate |
| POST | `/email-templates/{id}/archive` | EmailTemplateController::archive |
| POST | `/email-templates/{id}/preview` | EmailTemplateController::preview |

## Finance (10)
| Method | Path | Handler |
|---|---|---|
| GET | `/finance/dashboard` | FinanceController::dashboard |
| GET | `/finance/reports` | FinanceController::report |
| GET | `/finance/rates` | FinanceController::rates |
| POST | `/finance/rates` | FinanceController::addRate |
| GET | `/finance/settings` | FinanceController::settings |
| PUT | `/finance/settings` | FinanceController::updateSettings |
| POST | `/finance/tax-rates` | FinanceController::addTaxRate |
| PUT | `/finance/tax-rates/{id}` | FinanceController::setTaxRateActive |
| GET | `/finance/audit` | FinanceController::audit |
| GET | `/finance/audit/verify` | FinanceController::verifyAudit |

## Form (14)
| Method | Path | Handler |
|---|---|---|
| GET | `/forms` | FormController::list |
| POST | `/forms` | FormController::create |
| GET | `/forms/{id}` | FormController::get |
| PUT | `/forms/{id}` | FormController::update |
| POST | `/forms/{id}/publish` | FormController::setStatus |
| POST | `/forms/{id}/unpublish` | FormController::setStatus |
| POST | `/forms/{id}/archive` | FormController::setStatus |
| POST | `/forms/{id}/duplicate` | FormController::duplicate |
| GET | `/forms/{id}/submissions` | FormController::submissions |
| POST | `/forms/{id}/submissions` | FormController::staffSubmit |
| GET | `/forms/{id}/submissions.csv` | FormController::exportCsv |
| PUT | `/form-submissions/{id}` | FormController::updateSubmission |
| GET | `/public/forms/{token}` | FormController::publicGet |
| POST | `/public/forms/{token}/submit` | FormController::publicSubmit |

## Group (12)
| Method | Path | Handler |
|---|---|---|
| GET | `/groups` | GroupController::list |
| POST | `/groups` | GroupController::create |
| GET | `/groups/{id}` | GroupController::get |
| PUT | `/groups/{id}` | GroupController::update |
| POST | `/groups/{id}/archive` | GroupController::archive |
| POST | `/groups/{id}/restore` | GroupController::archive |
| PUT | `/groups/{id}/rooms` | GroupController::setRooms |
| POST | `/groups/{id}/registrations` | GroupController::register |
| PUT | `/groups/{id}/registrations/{rid}` | GroupController::updateRegistration |
| POST | `/groups/{id}/registrations/{rid}/cancel` | GroupController::cancelRegistration |
| POST | `/groups/{id}/subtrips` | GroupController::createSubTrip |
| POST | `/groups/{id}/sync` | GroupController::syncSubTrips |

## Invoice (9)
| Method | Path | Handler |
|---|---|---|
| GET | `/invoices` | InvoiceController::list |
| POST | `/invoices` | InvoiceController::create |
| GET | `/invoices/{id}` | InvoiceController::get |
| PUT | `/invoices/{id}` | InvoiceController::update |
| POST | `/invoices/{id}/issue` | InvoiceController::issue |
| POST | `/invoices/{id}/adjustments` | InvoiceController::adjust |
| POST | `/invoices/{id}/void` | InvoiceController::void |
| PUT | `/invoices/{id}/schedule` | InvoiceController::setSchedule |
| GET | `/invoices/{id}/print` | InvoiceController::printView |

## Itinerary (6)
| Method | Path | Handler |
|---|---|---|
| GET | `/trips/{id}/itinerary` | ItineraryController::get |
| PUT | `/trips/{id}/itinerary` | ItineraryController::save |
| POST | `/trips/{id}/publish` | ItineraryController::publish |
| POST | `/trips/{id}/unpublish` | ItineraryController::unpublish |
| GET | `/trips/{id}/versions/{vid}` | ItineraryController::version |
| POST | `/trips/{id}/comments` | ItineraryController::staffComment |

## Library (12)
| Method | Path | Handler |
|---|---|---|
| GET | `/library/folders` | LibraryController::folders |
| POST | `/library/folders` | LibraryController::createFolder |
| PUT | `/library/folders/{id}` | LibraryController::updateFolder |
| DELETE | `/library/folders/{id}` | LibraryController::deleteFolder |
| GET | `/library/templates` | LibraryController::list |
| POST | `/library/templates` | LibraryController::create |
| GET | `/library/templates/{id}` | LibraryController::get |
| PUT | `/library/templates/{id}` | LibraryController::update |
| POST | `/library/templates/{id}/duplicate` | LibraryController::duplicate |
| POST | `/library/templates/{id}/archive` | LibraryController::archive |
| POST | `/library/templates/{id}/restore` | LibraryController::archive |
| POST | `/library/templates/{id}/use` | LibraryController::useOnTrip |

## Ops (14)
| Method | Path | Handler |
|---|---|---|
| GET | `/notifications` | OpsController::notifications |
| POST | `/notifications/read-all` | OpsController::readAll |
| POST | `/notifications/{id}/read` | OpsController::markNotification |
| POST | `/notifications/{id}/unread` | OpsController::markNotification |
| POST | `/notifications/{id}/dismiss` | OpsController::markNotification |
| GET | `/activity` | OpsController::activity |
| GET | `/followups` | OpsController::followups |
| POST | `/followups` | OpsController::createFollowup |
| POST | `/followups/{id}/cancel` | OpsController::cancelFollowup |
| GET | `/jobs` | OpsController::jobs |
| GET | `/jobs/status` | OpsController::schedulerStatus |
| POST | `/jobs/{id}/retry` | OpsController::retryJob |
| POST | `/jobs/{id}/cancel` | OpsController::cancelJob |
| POST | `/cron/run` | OpsController::cronRun |

## Payable (6)
| Method | Path | Handler |
|---|---|---|
| GET | `/payables` | PayableController::list |
| POST | `/payables` | PayableController::create |
| GET | `/payables/{id}` | PayableController::get |
| PUT | `/payables/{id}` | PayableController::update |
| POST | `/payables/{id}/payments` | PayableController::pay |
| POST | `/supplier-payments/{id}/reverse` | PayableController::reversePayment |

## Payment (5)
| Method | Path | Handler |
|---|---|---|
| GET | `/payments` | PaymentController::list |
| POST | `/payments` | PaymentController::create |
| POST | `/payments/{id}/reverse` | PaymentController::reverse |
| POST | `/payments/{id}/verify` | PaymentController::verify |
| POST | `/payments/{id}/refunds` | PaymentController::refund |

## Portal (7)
| Method | Path | Handler |
|---|---|---|
| POST | `/contacts/{id}/portal-account` | PortalController::createAccount |
| GET | `/portal/trips` | PortalController::list |
| GET | `/portal/trips/{id}` | PortalController::get |
| POST | `/portal/trips/{id}/select` | PortalController::select |
| POST | `/portal/trips/{id}/approve` | PortalController::approve |
| POST | `/portal/trips/{id}/request-changes` | PortalController::requestChanges |
| POST | `/portal/trips/{id}/comments` | PortalController::comment |

## Quote (9)
| Method | Path | Handler |
|---|---|---|
| GET | `/quotes` | QuoteController::list |
| POST | `/quotes` | QuoteController::create |
| GET | `/quotes/{id}` | QuoteController::get |
| PUT | `/quotes/{id}` | QuoteController::update |
| POST | `/quotes/{id}/duplicate` | QuoteController::duplicate |
| POST | `/quotes/{id}/send` | QuoteController::send |
| POST | `/quotes/{id}/decision` | QuoteController::decide |
| POST | `/quotes/{id}/convert` | QuoteController::convert |
| POST | `/quotes/{id}/archive` | QuoteController::archive |

## Staff (2)
| Method | Path | Handler |
|---|---|---|
| GET | `/staff` | StaffController::list |
| PATCH | `/staff/{id}` | StaffController::setActive |

## Supplier (7)
| Method | Path | Handler |
|---|---|---|
| GET | `/suppliers` | SupplierController::list |
| POST | `/suppliers` | SupplierController::create |
| GET | `/suppliers/{id}` | SupplierController::get |
| PUT | `/suppliers/{id}` | SupplierController::update |
| POST | `/suppliers/{id}/archive` | SupplierController::archive |
| POST | `/suppliers/{id}/restore` | SupplierController::archive |
| PUT | `/suppliers/{id}/contacts` | SupplierController::setContacts |

## System (1)
| Method | Path | Handler |
|---|---|---|
| GET | `/health` | inline health check |

## Task (8)
| Method | Path | Handler |
|---|---|---|
| GET | `/tasks` | TaskController::list |
| POST | `/tasks` | TaskController::create |
| GET | `/tasks/{id}` | TaskController::get |
| PUT | `/tasks/{id}` | TaskController::update |
| POST | `/tasks/{id}/complete` | TaskController::complete |
| POST | `/tasks/{id}/reopen` | TaskController::complete |
| POST | `/tasks/{id}/archive` | TaskController::archive |
| POST | `/tasks/{id}/comments` | TaskController::comment |

## Trip (7)
| Method | Path | Handler |
|---|---|---|
| GET | `/trips` | TripController::list |
| POST | `/trips` | TripController::create |
| GET | `/trips/{id}` | TripController::get |
| PUT | `/trips/{id}` | TripController::update |
| POST | `/trips/{id}/archive` | TripController::archive |
| POST | `/trips/{id}/restore` | TripController::archive |
| PUT | `/trips/{id}/travelers` | TripController::setTravelers |

## Workflow (12)
| Method | Path | Handler |
|---|---|---|
| GET | `/workflows/meta` | WorkflowController::meta |
| GET | `/workflows` | WorkflowController::list |
| POST | `/workflows` | WorkflowController::create |
| GET | `/workflows/{id}` | WorkflowController::get |
| PUT | `/workflows/{id}` | WorkflowController::update |
| POST | `/workflows/{id}/enable` | WorkflowController::setStatus |
| POST | `/workflows/{id}/disable` | WorkflowController::setStatus |
| POST | `/workflows/{id}/archive` | WorkflowController::setStatus |
| POST | `/workflows/{id}/duplicate` | WorkflowController::duplicate |
| POST | `/workflows/{id}/test` | WorkflowController::test |
| GET | `/workflows/{id}/executions` | WorkflowController::executions |
| POST | `/workflow-executions/{id}/retry` | WorkflowController::retryExecution |
