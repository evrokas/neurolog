# NeuroLog — Design System Implementation Specification

**Project:** NeuroLog  
**Product:** Shared Surgical Operations Logbook  
**Design system version:** 1.0  
**Implementation scope:** UI/UX design system and visual consistency  
**Primary identity:** Neuro Monogram (geometric letter N)

---

## 1. Objective

Implement a unified, modern design system for NeuroLog, a web application designed to maintain and manage the surgical operations logbook of a group of surgeons.

The design should communicate:

- Medical professionalism
- Precision and reliability
- Modern medical technology
- Clarity and efficiency
- Collaboration between surgeons
- Secure and structured clinical documentation

The application should look like a premium medical SaaS platform rather than a traditional hospital information system.

### Important implementation constraint

The application is already implemented.

**Do not rebuild the application, change its business logic, or introduce unnecessary architectural changes.**

The task is to establish and apply a consistent visual language to the existing interface.

Before making changes:

1. Inspect the existing application structure.
2. Identify the current CSS architecture and component system.
3. Identify reusable UI components.
4. Identify existing layouts, navigation, forms, tables, dialogs and dashboards.
5. Map the existing components to the new design tokens.
6. Implement the design system incrementally.

Preserve all existing functionality, routes, API integrations, database structures and application workflows.

---

## 2. Brand Identity

### 2.1 Product name

**NeuroLog**

Subtitle:

`SURGICAL ACTIVITY LOGBOOK`

Suggested brand tagline:

`Every Case. Every Detail. One Record.`

### 2.2 Logo direction

Use the selected Neuro Monogram concept.

The logo should be based on a custom geometric letter **N**, with visual inspiration from:

- Neural pathways
- Connected structures
- Precision engineering
- Medical technology

The monogram must work independently of the NeuroLog wordmark.

Required logo variants:

- Full logo: Symbol + NeuroLog
- Compact logo: Symbol only
- Horizontal logo for application navigation
- Monochrome version
- Light-background version
- Dark-background version

Do not substitute the logo with a generic brain icon or a medical cross.

If the original logo artwork is not available, create a clean geometric N monogram using CSS or SVG, designed so that it can later be replaced with the final vector artwork.

---

## 3. Color System

Use the following palette as the official NeuroLog color system.

### 3.1 Primary brand colors

| Token | HEX | Usage |
|---|---|---|
| `--nl-primary` | `#17365D` | Primary brand color, navigation, headings |
| `--nl-secondary` | `#20A6B2` | Logo accents, secondary actions, highlights |
| `--nl-accent` | `#3985D6` | Interactive elements, links, active states |

Deep Navy must remain the dominant brand color.

Neuro Cyan should be used selectively to create visual recognition.

Electric Blue should be reserved primarily for interactive elements.

### 3.2 Neutral colors

| Token | HEX | Usage |
|---|---|---|
| `--nl-background` | `#EAF0F5` | Main application background |
| `--nl-surface` | `#FFFFFF` | Cards, panels, forms |
| `--nl-text` | `#263445` | Primary text |
| `--nl-text-muted` | `#718096` | Secondary text, descriptions |
| `--nl-border` | `#D9E2EC` | Borders and separators |

### 3.3 Functional colors

| Token | HEX | Usage |
|---|---|---|
| `--nl-success` | `#27966F` | Completed, successful operations |
| `--nl-warning` | `#E5A33D` | Pending, attention required |
| `--nl-danger` | `#D9534F` | Errors, cancelled operations |
| `--nl-info` | `#3985D6` | Informational messages |

Functional colors must not replace the primary brand colors.

Use them only when communicating a meaningful state.

### 3.4 CSS variables

Create a centralized set of design tokens.

```css
:root {
  /* Brand */
  --nl-primary: #17365D;
  --nl-secondary: #20A6B2;
  --nl-accent: #3985D6;

  /* Backgrounds */
  --nl-background: #EAF0F5;
  --nl-surface: #FFFFFF;

  /* Typography */
  --nl-text: #263445;
  --nl-text-muted: #718096;

  /* Borders */
  --nl-border: #D9E2EC;

  /* Status */
  --nl-success: #27966F;
  --nl-warning: #E5A33D;
  --nl-danger: #D9534F;
  --nl-info: #3985D6;
}
```

Use these variables consistently throughout the application.

Avoid hardcoded colors in individual components.

---

## 4. Typography

The typography should be modern, clean and highly readable.

Preferred font:

**Inter**

Alternative: system UI sans-serif.

Suggested hierarchy:

| Element | Size | Weight |
|---|---:|---:|
| Page title | 26–30px | 650–700 |
| Section title | 18–21px | 600 |
| Card title | 15–17px | 600 |
| Body text | 14px | 400 |
| Secondary text | 12–13px | 400 |
| Form labels | 13px | 500 |
| Button text | 13–14px | 600 |

Typography guidelines:

- Avoid excessively large headings.
- Maintain a clear hierarchy.
- Use sufficient line height.
- Use consistent font weights.
- Avoid uppercase text except for short labels and metadata.
- Use tabular numerals for dates, counts and statistical values where appropriate.

---

## 5. Layout and Spacing

The interface should use a consistent spacing system based on multiples of 4px.

Recommended spacing scale:

| Token | Value |
|---|---:|
| `--nl-space-1` | 4px |
| `--nl-space-2` | 8px |
| `--nl-space-3` | 12px |
| `--nl-space-4` | 16px |
| `--nl-space-5` | 20px |
| `--nl-space-6` | 24px |
| `--nl-space-8` | 32px |
| `--nl-space-10` | 40px |
| `--nl-space-12` | 48px |

### General layout

Use:

- A clean application shell.
- Consistent navigation.
- Clearly separated content sections.
- Adequate whitespace.
- Responsive layouts.
- Predictable alignment and spacing.

Avoid unnecessary visual decoration.

### Main application background

Use `--nl-background` as the default background for the application workspace.

Use white surfaces for cards, forms, tables and other content containers.

---

## 6. Component Design

Establish reusable styles for the following components.

### 6.1 Buttons

Define four button variants:

**Primary**
- Background: Deep Navy
- Text: White
- Hover: Slightly darker navy

**Secondary**
- Background: White
- Text: Deep Navy
- Border: Neutral border

**Accent**
- Background: Neuro Cyan
- Text: White
- Hover: Slightly darker cyan

**Danger**
- Background: Red
- Text: White

Buttons should have:

- Consistent height (36–40px)
- Border radius of 6–8px
- Consistent horizontal padding
- Clear hover and focus states
- Disabled states
- Appropriate loading states

Avoid oversized pill-shaped buttons.

### 6.2 Cards and panels

Cards should use:

- White background
- Subtle border
- 8–12px border radius
- Consistent internal padding
- Minimal or no shadow

Use shadows only when necessary to establish visual hierarchy.

Cards should not look excessively elevated.

### 6.3 Forms

Forms are particularly important because the application records surgical procedures.

Use consistent styling for:

- Text inputs
- Textareas
- Select fields
- Date pickers
- Checkboxes
- Radio buttons
- Search fields
- Form groups

Requirements:

- Labels must always be visible.
- Required fields must be clearly indicated.
- Validation errors should appear close to the relevant field.
- Help text should use muted typography.
- Inputs should have consistent height and border radius.
- Focus states should use the brand accent color.
- Disabled and read-only states must be distinguishable.

### 6.4 Tables

Tables are a central part of the surgical logbook.

Use:

- Clear column headers.
- Subtle horizontal separators.
- Adequate row height.
- Consistent typography.
- Readable date and numeric alignment.
- Clear hover states.
- Responsive overflow handling.

Avoid excessive grid lines and dense visual decoration.

### 6.5 Status badges

Create reusable status badges for surgical procedures.

Examples:

- Completed
- Pending
- Scheduled
- Cancelled
- In Progress

Use soft background tints with readable text.

Status must never be communicated by color alone. Always include a text label.

### 6.6 Modals and dialogs

Use:

- White background
- Consistent border radius
- Clear modal title
- Logical action placement
- Proper spacing
- Subtle overlay

Ensure dialogs remain usable on mobile screens.

### 6.7 Notifications

Create consistent styles for:

- Success notifications
- Warning notifications
- Error notifications
- Informational notifications

Keep messages concise and non-intrusive.

---

## 7. Navigation

The navigation should be visually consistent with the NeuroLog identity.

Use Deep Navy as the primary navigation color where a dark sidebar is already present.

The active navigation item should be highlighted using a restrained cyan or blue accent.

Requirements:

- Clear active state
- Consistent iconography
- Consistent spacing
- Readable navigation labels
- Responsive collapse behavior
- Clear user/account section

Do not introduce new navigation items or modify the existing information architecture unless required for visual consistency.

---

## 8. Dashboard Design

The dashboard should provide an immediate overview of the surgical team's activity.

Use a modular card-based layout.

Potential visual components include:

- Total operations
- Operations this month
- Operations by surgeon
- Operations by hospital
- Operations by procedure
- Recent operations
- Upcoming procedures, if supported by existing functionality

### Statistics cards

Statistics cards should feature:

1. A small descriptive label.
2. A prominent numerical value.
3. Optional trend or secondary information.
4. A restrained icon or graphic.

Use Deep Navy for primary statistics and Neuro Cyan for selected highlights.

Do not invent or add data that does not exist in the current application.

Preserve existing dashboard metrics and calculations.

---

## 9. Iconography

Use one consistent icon family throughout the application.

Preferred: Lucide Icons.

Guidelines:

- Use simple outline icons.
- Standard icon size: 18–20px.
- Use consistent stroke width.
- Avoid mixing filled and outlined icon styles.
- Avoid decorative icons that do not communicate useful information.

Icons should support navigation, actions and information recognition.

---

## 10. Responsive Design

The design system must support:

- Desktop
- Laptop
- Tablet
- Mobile

### Desktop

- Use the full navigation layout.
- Use multi-column dashboard cards.
- Allow tables to display their full information where possible.

### Tablet

- Adapt dashboard grids.
- Reduce unnecessary spacing.
- Ensure forms remain comfortable to use.

### Mobile

- Use a compact navigation system.
- Stack dashboard cards vertically or in a suitable compact grid.
- Keep primary actions accessible.
- Make forms full-width.
- Use responsive tables or card-based representations where necessary.
- Prevent horizontal overflow.
- Maintain readable font sizes and touch targets.

Responsive behavior must preserve the existing workflows.

---

## 11. Accessibility

Follow WCAG 2.1 AA principles wherever practical.

Requirements:

- Sufficient text contrast.
- Visible keyboard focus.
- Proper form labels.
- Semantic HTML.
- Accessible button labels.
- Keyboard-accessible dialogs.
- Status indicators that do not depend on color alone.

Do not sacrifice readability for visual minimalism.

---

## 12. Dark Mode

Do not implement dark mode as part of the initial scope unless the existing application already supports it.

However, structure the design tokens so that a future dark theme can be introduced without rewriting individual components.

---

## 13. Implementation Strategy

Implement the design system in the following phases.

### Phase 1 — Application inspection

- Inspect the existing codebase.
- Identify the frontend framework and CSS methodology.
- Identify global styles.
- Identify existing reusable components.
- Identify inconsistent styling patterns.
- Prepare a short implementation plan.

### Phase 2 — Design tokens

- Implement the NeuroLog color palette.
- Implement typography variables.
- Implement spacing variables.
- Implement border and radius variables.
- Implement shadows and component dimensions.
- Centralize the design tokens.

### Phase 3 — Core components

Update the existing:

- Buttons
- Inputs
- Selects
- Forms
- Cards
- Tables
- Badges
- Modals
- Notifications
- Navigation elements

Prefer modifying existing components rather than introducing duplicates.

### Phase 4 — Application layouts

Apply the design system to:

- Main application shell
- Dashboard
- Surgical operations listing
- Operation creation/editing forms
- Operation details
- Surgeon management
- Hospital management
- Reports and statistics
- User profile and settings

Only update screens and modules that actually exist in the application.

### Phase 5 — Responsive refinement

Test the application at:

- 1440px desktop
- 1280px laptop
- 1024px tablet
- 768px tablet/mobile transition
- 390px mobile

Correct layout inconsistencies and horizontal overflow.

### Phase 6 — Quality assurance

Verify:

- All existing features still work.
- No business logic has changed.
- No API contracts have changed.
- No database changes have been introduced.
- All interactive elements remain functional.
- Form validation still works.
- Navigation remains intact.
- Responsive layouts are usable.
- Colors and typography are consistent.

---

## 14. Technical Constraints

1. Respect the existing technology stack.
2. Do not introduce a new frontend framework.
3. Do not replace working application components unnecessarily.
4. Do not introduce additional dependencies unless justified.
5. Do not change backend functionality.
6. Do not modify database schemas.
7. Do not change authentication or authorization.
8. Do not change existing API contracts.
9. Do not remove existing functionality.
10. Keep the design system modular and maintainable.

If an existing component already performs the required function, improve its presentation rather than replacing it.

---

## 15. Expected Deliverables

At the end of implementation, provide:

1. Centralized NeuroLog design tokens.
2. Consistent global typography and spacing.
3. Updated reusable UI components.
4. Updated application shell and navigation.
5. Updated existing dashboard.
6. Updated existing forms and tables.
7. Responsive desktop, tablet and mobile layouts.
8. Consistent status and notification styles.
9. Documentation describing the implemented design system.
10. A list of modified files and components.

Include screenshots of the updated application where possible.

---

## 16. Final Design Principles

The following principles must guide every design decision:

**Professional, not institutional.**

NeuroLog should look like a modern medical technology product, not an outdated hospital management system.

**Minimal, not empty.**

Use whitespace intelligently while preserving useful information density.

**Consistent, not repetitive.**

Reusable components should share the same visual language without making every screen look identical.

**Functional, not decorative.**

Every visual element should improve usability, hierarchy or information recognition.

**Trustworthy, not flashy.**

Avoid gradients, excessive shadows, unnecessary animations and overly saturated colors.

The final result should be a coherent, premium-quality medical SaaS interface built around the NeuroLog identity.

**The most important requirement: improve the visual quality and consistency of the existing application while preserving 100% of its current functionality.**
