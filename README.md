# Developer Task – Barbershop Booking System

A simple online booking application for two barbershops. Customers can choose a service, a stylist, a time slot, and submit a reservation.

## Architecture

- **Backend** – PHP application (Nette Framework, Doctrine ORM, GraphQL API), SQLite database
- **Frontend** – Next.js (React, Apollo Client, Tailwind CSS)
- Communication goes through a GraphQL endpoint on the backend

## Getting Started

### Requirements

- Docker + Docker Compose

### Steps

```bash
# 1. Start the containers (env files are created automatically)
make up

# 2. Run database migrations and load test data
make db-reset
```

The default configuration uses `docker-compose.local.yml`, which maps ports to localhost. Once running:

- **Frontend** at [http://localhost:3000](http://localhost:3000)
- **GraphQL API** at [http://localhost:8080/graphql](http://localhost:8080/graphql)

### Using the App

At [http://localhost:3000](http://localhost:3000) you'll find a list of available barbershops. Click on one to open its detail page, where you can:

1. Select a service (haircut, shave, …)
2. Choose a stylist and a date
3. Click an available time slot
4. Fill in your name and contact (email or phone) and submit with the **Book** button

### Booking Administration

The business panel is available at [http://localhost:3000/business-panel](http://localhost:3000/business-panel).

Credentials:

| Field    | Value    |
|----------|----------|
| Username | `admin`  |
| Password | `barber` |

After logging in you'll see all bookings grouped by date. You can switch between barbershops using the tabs at the top. For each booking in **Pending** status you can:

- **Confirm** – confirm the booking
- **Reject** – reject the booking

Confirmed and rejected bookings display their status and no further actions are available.

## Task

**Customers are reporting that after submitting a booking they receive two confirmations for the same time slot.** In the administration panel we can see two identical bookings — same customer, same stylist, same time. It happens occasionally, not every time.

Find out why this is happening and propose a fix on the backend.

> We recommend using AI tools while working on this task.

## Bonus Task

Create a Claude Code skill that solves a problem you actually hit while working on the main task. The skill must be functional and usable in the Claude Code CLI.

### Submitted skill: `doctrine-uniqueness-constraint-auditor`

Generalizes the core problem we hit while fixing this task: a business uniqueness invariant enforced only at the application layer, producing occasional duplicate rows under concurrency. The skill guides an auditor through identifying the invariant, choosing the right DB-level constraint for the dialect (plain UNIQUE / partial UNIQUE / generated-column / `EXCLUDE USING gist`), and working around Doctrine's SQLite limitations (no partial-index WHERE emission, no WHERE introspection — solved via a `postGenerateSchema` listener). Also covers handler-level exception translation and an integration-test template for the predicate-sensitive scenarios.

Location: [`.claude/skills/doctrine-uniqueness-constraint-auditor/SKILL.md`](.claude/skills/doctrine-uniqueness-constraint-auditor/SKILL.md).

Installation (zero-config): clone this repo and open it with Claude Code. Project-local skills in `.claude/skills/` are auto-discovered. To install globally, copy the directory to `~/.claude/skills/`.

Trigger phrases (from the skill's `description`): production reports of occasional duplicates, `findOneBy` / `existsBy` followed by `persist`, mentions of "race condition" / "TOCTOU" / "double-submit", migrations for entities where double-booking would corrupt state, or code review around unique-constraint-adjacent code.

## Discussion Questions

The following questions are **not part of the submission** — we'll go through them together during the interview.

**Security & Architecture**

1. The GraphQL API has no authorization — how would you approach this and where would you implement it?
2. Walk me through a code review of the entire booking rejection flow — from the GraphQL mutation to `Booking::reject()`. What would you flag?
3. Query handlers in this project return domain entities directly. Why is this a problem in a CQRS architecture, and what would you use instead?
4. The `Booking` aggregate raises no domain events when confirmed or rejected. You've just implemented webhook delivery — how would domain events have changed your implementation?
5. The `price` field on `Service` is stored as a `float`. You're reviewing a PR that adds multi-currency support — what's your first comment?

**Scalability & Reliability**

6. How would you design a system serving tens of thousands of businesses that together handle hundreds of thousands of requests per day?
7. This project runs as a single service. How would you approach logging if it were split into multiple microservices — how do you trace a single booking request across service boundaries?

**AI in Backend Development**

8. How did working with AI on this task change how you'd normally approach a code review of your own PR?
9. Where in this project's CQRS architecture would you hook in an LLM call? What changes when an external API with non-deterministic latency is part of your command handler?

**General**

10. Did you notice anything unusual or non-standard in the project? If so, what was it and how would you approach it differently?

## Submission

Push your solution to a repository on any code hosting platform (GitHub, GitLab, Bitbucket, etc.) and share the link with us.

## Useful Commands

```bash
make up          # build and start containers
make down        # stop and remove containers
make db-reset    # run migrations and load fixtures
make fixtures    # load fixtures only (clears existing data)
make bash        # open a shell in the backend container
make logs        # tail backend container logs
```
