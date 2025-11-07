#!/usr/bin/env python3
"""
Create GitHub Project with Roadmap for ShareFast API
Creates milestones and issues for backend API development
"""

import os
import sys
import requests
from datetime import datetime, timedelta
from typing import List, Dict, Optional

GITHUB_API_BASE = "https://api.github.com"

def get_github_token() -> Optional[str]:
    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    if not token:
        print("ERROR: GITHUB_TOKEN or GH_TOKEN environment variable not set")
        return None
    return token

def get_repo_info() -> tuple[str, str]:
    import subprocess
    try:
        result = subprocess.run(
            ["git", "remote", "get-url", "origin"],
            capture_output=True,
            text=True
        )
        if result.returncode == 0:
            url = result.stdout.strip()
            if "github.com" in url:
                parts = url.split("github.com")[1].strip("/").replace(".git", "").split("/")
                if len(parts) >= 2:
                    return parts[0], parts[1]
    except:
        pass
    
    owner = input("GitHub repository owner: ").strip()
    repo = input("GitHub repository name: ").strip()
    return owner, repo

def create_milestone(token: str, owner: str, repo: str, title: str, description: str, due_date: Optional[str] = None) -> Optional[int]:
    url = f"{GITHUB_API_BASE}/repos/{owner}/{repo}/milestones"
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.github.v3+json"
    }
    
    data = {"title": title, "description": description}
    if due_date:
        data["due_on"] = due_date
    
    response = requests.post(url, json=data, headers=headers)
    if response.status_code == 201:
        milestone = response.json()
        print(f"✓ Created milestone: {title} (#{milestone['number']})")
        return milestone["number"]
    else:
        print(f"ERROR: Failed to create milestone '{title}': {response.status_code}")
        return None

def create_issue(token: str, owner: str, repo: str, title: str, body: str, milestone: Optional[int] = None, labels: List[str] = None) -> Optional[int]:
    url = f"{GITHUB_API_BASE}/repos/{owner}/{repo}/issues"
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.github.v3+json"
    }
    
    data = {"title": title, "body": body}
    if milestone:
        data["milestone"] = milestone
    if labels:
        data["labels"] = labels
    
    response = requests.post(url, json=data, headers=headers)
    if response.status_code == 201:
        return response.json()["number"]
    return None

def get_api_roadmap_data() -> Dict:
    today = datetime.now()
    
    return {
        "milestones": [
            {
                "title": "Phase 1: API Foundation (0-6 months)",
                "description": "Core API functionality and infrastructure",
                "due_date": (today + timedelta(days=180)).isoformat(),
                "issues": [
                    {"title": "RESTful API Endpoints", "body": "Complete all core REST API endpoints for session management", "labels": ["enhancement", "api"]},
                    {"title": "Database Schema Optimization", "body": "Optimize database schema for performance and scalability", "labels": ["enhancement", "database", "performance"]},
                    {"title": "WebSocket Relay Server", "body": "Implement WebSocket relay server for real-time communication", "labels": ["enhancement", "websocket", "infrastructure"]},
                    {"title": "API Authentication & Security", "body": "Implement secure authentication and authorization", "labels": ["enhancement", "security", "api"]},
                    {"title": "Rate Limiting & Throttling", "body": "Add rate limiting to prevent abuse", "labels": ["enhancement", "security", "api"]},
                    {"title": "API Documentation", "body": "Create comprehensive API documentation", "labels": ["enhancement", "documentation"]}
                ]
            },
            {
                "title": "Phase 2: Scalability & Performance (6-12 months)",
                "description": "Scale API for high traffic and optimize performance",
                "due_date": (today + timedelta(days=365)).isoformat(),
                "issues": [
                    {"title": "Database Query Optimization", "body": "Optimize database queries for better performance", "labels": ["enhancement", "database", "performance"]},
                    {"title": "Caching Layer", "body": "Implement Redis caching for frequently accessed data", "labels": ["enhancement", "infrastructure", "performance"]},
                    {"title": "Load Balancing", "body": "Set up load balancing for API servers", "labels": ["enhancement", "infrastructure"]},
                    {"title": "API Monitoring & Logging", "body": "Implement comprehensive monitoring and logging", "labels": ["enhancement", "infrastructure"]},
                    {"title": "Auto-Scaling Infrastructure", "body": "Implement auto-scaling for GCP infrastructure", "labels": ["enhancement", "infrastructure", "gcp"]}
                ]
            },
            {
                "title": "Phase 3: Advanced Features (12-24 months)",
                "description": "Advanced API features and integrations",
                "due_date": (today + timedelta(days=730)).isoformat(),
                "issues": [
                    {"title": "Public API & SDK", "body": "Create public API and SDK for developers", "labels": ["enhancement", "api", "platform"]},
                    {"title": "Webhook Support", "body": "Implement webhook system for event notifications", "labels": ["enhancement", "api", "feature"]},
                    {"title": "GraphQL API", "body": "Add GraphQL endpoint alongside REST API", "labels": ["enhancement", "api", "graphql"]},
                    {"title": "API Versioning", "body": "Implement API versioning strategy", "labels": ["enhancement", "api"]},
                    {"title": "Cross-Device Sync API", "body": "API endpoints for syncing across devices", "labels": ["enhancement", "api", "feature"]}
                ]
            },
            {
                "title": "Phase 4: Platform & Enterprise (24+ months)",
                "description": "Platform features and enterprise capabilities",
                "due_date": (today + timedelta(days=1095)).isoformat(),
                "issues": [
                    {"title": "Enterprise API Features", "body": "Add enterprise-specific API features", "labels": ["enhancement", "api", "enterprise"]},
                    {"title": "API Analytics Dashboard", "body": "Create analytics dashboard for API usage", "labels": ["enhancement", "api", "analytics"]},
                    {"title": "Multi-Tenant Support", "body": "Support multiple tenants in API", "labels": ["enhancement", "api", "enterprise"]},
                    {"title": "API Marketplace Integration", "body": "Integrate with API marketplace platforms", "labels": ["enhancement", "api", "platform"]}
                ]
            }
        ]
    }

def main():
    print("=" * 60)
    print("ShareFast API GitHub Project & Roadmap Setup")
    print("=" * 60)
    print()
    
    token = get_github_token()
    if not token:
        return 1
    
    owner, repo = get_repo_info()
    print(f"Repository: {owner}/{repo}\n")
    
    roadmap = get_api_roadmap_data()
    
    print("Creating milestones and issues...\n")
    
    for milestone_data in roadmap["milestones"]:
        milestone_num = create_milestone(
            token, owner, repo,
            milestone_data["title"],
            milestone_data["description"],
            milestone_data.get("due_date")
        )
        
        if milestone_num:
            for issue_data in milestone_data.get("issues", []):
                issue_num = create_issue(
                    token, owner, repo,
                    issue_data["title"],
                    f"{issue_data['body']}\n\n**Milestone**: {milestone_data['title']}",
                    milestone_num,
                    issue_data.get("labels", [])
                )
                if issue_num:
                    print(f"  ✓ Created issue #{issue_num}: {issue_data['title']}")
        print()
    
    print("=" * 60)
    print("Setup Complete!")
    print("=" * 60)
    return 0

if __name__ == "__main__":
    sys.exit(main())

