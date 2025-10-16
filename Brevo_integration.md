# Brevo Integration Guide

This document details the functionality and implementation of the Brevo API integration for the Let's Roll SEO Pages plugin.

## Core Logic: Contact Lookup

The primary function for interacting with Brevo is to find a contact based on their Let's Roll `SKATENAME`. After extensive debugging, the following is the **only** correct and reliable method.

### `lr_find_brevo_contact_by_skatename($skateName)`

This function, located in `brevo-integration.php`, is responsible for finding a single contact.

**Endpoint:** `GET https://api.brevo.com/v3/contacts`

**Method:**
It performs a `GET` request to the main contacts endpoint. It does **not** use any other endpoint like `/search`.

**Filtering:**
The crucial part of the request is the `filter` query parameter. The correct syntax for filtering by a custom attribute is:
`filter=equals(ATTRIBUTE_NAME,"Value")`

**Implementation Example:**
To find the contact for a user with the skatename `rollerskatingcharlie`, the function constructs the following URL:
`https://api.brevo.com/v3/contacts?filter=equals(SKATENAME,"rollerskatingcharlie")`

The `filter` parameter's value is **not** separately URL-encoded before being passed to `add_query_arg`, as the function handles the encoding. Double-encoding will break the request.

This is the definitive and tested method for looking up a contact by their skatename. All other functionality must be built upon this working foundation.
